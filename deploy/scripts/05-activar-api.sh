#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# Activa el API en un Apache que ya sirve otros sitios, en un solo paso y
# sin dejar el servicio roto: comprueba antes, verifica despues y se
# revierte solo si algo falla.
#
# Uso:  sudo ./05-activar-api.sh --dominio dev.minkafab.com --puerto 8444
#
# Requiere que 02 y 03 ya se hayan ejecutado (codigo en /srv/jocotoco y
# certificado en /etc/jocotoco/tls). No modifica el MPM, no deshabilita
# sitios ajenos y no escribe nada fuera de:
#   /etc/apache2/sites-available/jocotoco-api.conf
#   /etc/apache2/sites-enabled/jocotoco-api.conf  (enlace)
# ---------------------------------------------------------------------------
set -euo pipefail

ORIGEN="$(cd "$(dirname "$0")/../.." && pwd)"
DOMINIO=""
PUERTO="8444"
DESTINO="/srv/jocotoco"
TLS="/etc/jocotoco/tls"
RAIZ_CA="/etc/step/certs/root_ca.crt"
SITIO="/etc/apache2/sites-available/jocotoco-api.conf"

while [[ $# -gt 0 ]]; do
    case "$1" in
        --dominio) DOMINIO="$2"; shift 2 ;;
        --puerto) PUERTO="$2"; shift 2 ;;
        --origen) ORIGEN="$2"; shift 2 ;;
        *) echo "Parametro desconocido: $1" >&2; exit 1 ;;
    esac
done

[[ $EUID -eq 0 ]] || { echo "Ejecute este script con sudo." >&2; exit 1; }
[[ -n "$DOMINIO" ]] || { echo "Indique --dominio <nombre>" >&2; exit 1; }

fallo() { echo >&2; echo "ERROR: $*" >&2; }

# --- 1. Comprobaciones previas: nada se toca si algo falta ---------------
echo "==> Comprobando el estado del servidor"

problemas=()

[[ -f "$DESTINO/public/index.php" ]] || problemas+=("falta la aplicacion en $DESTINO (ejecute 02-instalar-api.sh)")
[[ -s "$TLS/servidor.crt" ]] || problemas+=("falta el certificado $TLS/servidor.crt (ejecute 03-emitir-cert-servidor.sh)")
[[ -s "$TLS/servidor.key" ]] || problemas+=("falta la llave $TLS/servidor.key")
[[ -s "$RAIZ_CA" ]] || problemas+=("falta la raiz de la CA en $RAIZ_CA")
[[ -f "$ORIGEN/deploy/apache/jocotoco-api.conf" ]] || problemas+=("no encuentro la plantilla del VirtualHost en $ORIGEN")

# El certificado tiene que servir para este nombre.
if [[ -s "$TLS/servidor.crt" ]] && command -v openssl >/dev/null 2>&1; then
    if ! openssl x509 -in "$TLS/servidor.crt" -noout -text 2>/dev/null | grep -q "DNS:${DOMINIO}\b"; then
        problemas+=("el certificado no incluye ${DOMINIO} como SAN (reemita con 03 --dominio ${DOMINIO})")
    fi
fi

# El socket del pool de php-fpm tiene que existir, o el API dara 503.
socket_pool="$(grep -oP '^\s*listen\s*=\s*\K\S+' /etc/php/8.3/fpm/pool.d/jocotoco.conf 2>/dev/null | tail -1 || true)"
if [[ -n "$socket_pool" && ! -S "$socket_pool" ]]; then
    problemas+=("el socket del pool de php-fpm no existe: ${socket_pool} (systemctl restart php8.3-fpm)")
fi

# El puerto tiene que estar libre o ser de Apache.
if command -v ss >/dev/null 2>&1; then
    ocupa="$(ss -ltnpH 2>/dev/null | awk -v p=":${PUERTO}\$" '$4 ~ p {print $6}' | head -1 || true)"
    if [[ -n "$ocupa" && "$ocupa" != *apache2* ]]; then
        problemas+=("el puerto ${PUERTO} lo ocupa otro proceso: ${ocupa}")
    fi
fi

# Ningun otro sitio habilitado puede declarar el mismo nombre en el mismo puerto.
for archivo in /etc/apache2/sites-enabled/*.conf; do
    [[ -e "$archivo" ]] || continue
    [[ "$(basename "$archivo")" == "jocotoco-api.conf" ]] && continue
    if grep -qE "^[[:space:]]*ServerName[[:space:]]+${DOMINIO}([[:space:]]|$)" "$archivo" 2>/dev/null \
       && grep -qE "<VirtualHost[^>]*:${PUERTO}>" "$archivo" 2>/dev/null; then
        problemas+=("$(basename "$archivo") ya sirve ${DOMINIO} en el puerto ${PUERTO}")
    fi
done

if (( ${#problemas[@]} > 0 )); then
    fallo "no se activo nada. Corrija lo siguiente:"
    printf '         - %s\n' "${problemas[@]}" >&2
    exit 1
fi

mpm="$(apache2ctl -M 2>/dev/null | grep -oE 'mpm_(event|worker|prefork)_module' | head -1 || true)"
echo "    MPM en uso: ${mpm:-desconocido} (no se modifica)"
echo "    Otros sitios habilitados: $(ls -1 /etc/apache2/sites-enabled/ 2>/dev/null | grep -v '^jocotoco-api' | tr '\n' ' ')"

# --- 2. Instantanea del estado, para poder volver atras ------------------
marca="$(date +%Y%m%d-%H%M%S)"
respaldo="/var/backups/jocotoco-apache-${marca}"
install -d -m 0700 /var/backups
{
    echo "# Estado de Apache antes de activar el API (${marca})"
    echo "## sites-enabled"; ls -1 /etc/apache2/sites-enabled/ 2>/dev/null
    echo "## conf-enabled"; ls -1 /etc/apache2/conf-enabled/ 2>/dev/null
    echo "## mods-enabled"; ls -1 /etc/apache2/mods-enabled/ 2>/dev/null
} > "${respaldo}.txt"
[[ -f "$SITIO" ]] && cp -a "$SITIO" "${respaldo}-vhost.conf"

apache_estaba_activo=0
systemctl is-active --quiet apache2 && apache_estaba_activo=1
echo "    Apache estaba $([[ $apache_estaba_activo -eq 1 ]] && echo activo || echo detenido)"
echo "    Estado guardado en ${respaldo}.txt"

# --- 3. Generar el VirtualHost ------------------------------------------
echo "==> Generando el VirtualHost para ${DOMINIO}:${PUERTO}"
sed "s/api\.jocotoco\.local/${DOMINIO}/g" "$ORIGEN/deploy/apache/jocotoco-api.conf" > "$SITIO"

if [[ "$PUERTO" != "443" ]]; then
    sed -i -e "s/^<VirtualHost \*:443>/<VirtualHost *:${PUERTO}>/" \
           -e "/=== INICIO REDIRECCION HTTP ===/,/=== FIN REDIRECCION HTTP ===/d" \
           "$SITIO"
    # El Listen va dentro del sitio: al deshabilitarlo se retira con el.
    sed -i "1i # Puerto propio del API (se retira al deshabilitar el sitio).\nListen ${PUERTO}\n" "$SITIO"
fi

# Cualquier conf de puerto de instalaciones anteriores estorba.
a2disconf -q jocotoco-puerto 2>/dev/null || true
rm -f /etc/apache2/conf-available/jocotoco-puerto.conf

# --- 4. Activar, con reversion automatica -------------------------------
revertir() {
    echo >&2
    echo "==> Revirtiendo: se deshabilita el API y se restaura Apache" >&2
    a2dissite -q jocotoco-api 2>/dev/null || true

    if (( apache_estaba_activo )); then
        systemctl restart apache2 2>/dev/null || true
        if systemctl is-active --quiet apache2; then
            echo "    Apache volvio a estar activo; sus otros sitios siguen sirviendo." >&2
        else
            echo "    ATENCION: Apache no volvio a arrancar. Revise:" >&2
            echo "      apache2ctl configtest" >&2
            echo "      journalctl -xeu apache2.service --no-pager | tail -20" >&2
            echo "      estado previo guardado en ${respaldo}.txt" >&2
        fi
    fi
}

echo "==> Habilitando el sitio"
a2ensite -q jocotoco-api

if ! apache2ctl configtest 2>&1 | tail -2; then
    revertir
    fallo "la configuracion no es valida (ver arriba)."
    exit 1
fi

if (( apache_estaba_activo )); then accion=reload; else accion=start; fi
echo "==> Apache: ${accion}"

if ! systemctl "$accion" apache2 || ! systemctl is-active --quiet apache2; then
    revertir
    fallo "Apache no pudo ${accion} con el API habilitado."
    echo "       journalctl -xeu apache2.service --no-pager | tail -20" >&2
    exit 1
fi

# --- 5. Verificacion de extremo a extremo -------------------------------
echo "==> Verificando el API"
sleep 1
verifica() {
    curl -s -o /dev/null -w '%{http_code}' --max-time 10 \
        --noproxy '*' --resolve "${DOMINIO}:${PUERTO}:127.0.0.1" \
        --cacert "$RAIZ_CA" "https://${DOMINIO}:${PUERTO}$1" 2>/dev/null || echo 000
}

salud="$(verifica /v1/salud)"
protegida="$(verifica /v1/grabaciones)"
echo "    GET /v1/salud        -> ${salud}   (esperado 200)"
echo "    GET /v1/grabaciones  -> ${protegida}   (esperado 403, sin certificado de cliente)"

if [[ "$salud" != "200" || "$protegida" != "403" ]]; then
    revertir
    fallo "el API no respondio lo esperado; no se deja habilitado."
    echo "       Revise:  tail -20 /var/log/apache2/jocotoco-api.error.log" >&2
    echo "                tail -5 /var/log/jocotoco/api.log" >&2
    echo "                systemctl status php8.3-fpm --no-pager | tail -5" >&2
    exit 1
fi

cat <<RESUMEN

===========================================================================
 API activo en https://${DOMINIO}:${PUERTO}

   Salud (sin certificado) : https://${DOMINIO}:${PUERTO}/v1/salud
   Resto de rutas          : requieren certificado de cliente de step-ca

 Sus otros sitios siguen habilitados y sin cambios:
   $(ls -1 /etc/apache2/sites-enabled/ 2>/dev/null | grep -v '^jocotoco-api' | tr '\n' ' ')

 Para desactivar el API por completo en cualquier momento:
   sudo a2dissite jocotoco-api && sudo systemctl reload apache2

 Siguiente paso: registrar la primera Raspberry
   sudo ./04-registrar-dispositivo.sh rpi-yanacocha-01
===========================================================================
RESUMEN
