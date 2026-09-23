#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# Habilita el alta automatica de dispositivos: POST /v1/inscripcion entrega
# un token de un solo uso de step-ca a quien presente el secreto de fabrica.
#
# Uso:  sudo ./08-habilitar-inscripcion.sh \
#            --secreto 'sk_factory_9f83a02b11c' \
#            --dominio dev.minkafab.com --puerto 8444
#
#       sudo ./08-habilitar-inscripcion.sh --generar-secreto ...   (lo crea)
#
# El API no recibe la contrasena de la CA: se instala un envoltorio
# (/usr/local/sbin/jocotoco-emitir-token) y una regla de sudo acotada a ese
# unico comando. Al final se comprueba el flujo completo pidiendo un token
# de prueba, que despues queda bloqueado.
# ---------------------------------------------------------------------------
set -euo pipefail

ORIGEN="$(cd "$(dirname "$0")/../.." && pwd)"
SECRETO=""
GENERAR=false
DOMINIO=""
PUERTO=""
DISPOSITIVOS=""
MAX_TOKENS="2"
POOL="/etc/php/8.3/fpm/pool.d/jocotoco.conf"
STEPPATH="/etc/step"
DESTINO="/srv/jocotoco"

while [[ $# -gt 0 ]]; do
    case "$1" in
        --secreto) SECRETO="$2"; shift 2 ;;
        --generar-secreto) GENERAR=true; shift ;;
        --dominio) DOMINIO="$2"; shift 2 ;;
        --puerto) PUERTO="$2"; shift 2 ;;
        --dispositivos) DISPOSITIVOS="$2"; shift 2 ;;
        --max-tokens) MAX_TOKENS="$2"; shift 2 ;;
        *) echo "Parametro desconocido: $1" >&2; exit 1 ;;
    esac
done

[[ $EUID -eq 0 ]] || { echo "Ejecute este script con sudo." >&2; exit 1; }
[[ -f "$POOL" ]] || { echo "No encuentro $POOL; ejecute antes 02-instalar-api.sh." >&2; exit 1; }
[[ -f "$STEPPATH/password.txt" ]] || { echo "No encuentro $STEPPATH/password.txt; la CA debe estar en este servidor." >&2; exit 1; }
[[ -n "$DOMINIO" ]] || { echo "Indique --dominio (para verificar el endpoint)." >&2; exit 1; }

if $GENERAR; then
    SECRETO="sk_$(openssl rand -hex 16)"
fi

if [[ ${#SECRETO} -lt 16 ]]; then
    echo "ERROR: el secreto debe tener al menos 16 caracteres." >&2
    echo "       Use --secreto '<valor>' o --generar-secreto." >&2
    exit 1
fi

PUERTO="${PUERTO:-$(grep -oP '^<VirtualHost \*:\K[0-9]+' /etc/apache2/sites-available/jocotoco-api.conf 2>/dev/null | tail -1 || echo 443)}"

# --- 1. Envoltorio con privilegios y regla de sudo -----------------------
echo "==> Instalando el emisor de tokens"
install -m 0755 -o root -g root "$ORIGEN/deploy/bin/jocotoco-emitir-token" /usr/local/sbin/jocotoco-emitir-token

echo "==> Autorizando al usuario jocotoco a invocarlo (solo ese comando)"
sudoers="/etc/sudoers.d/jocotoco-inscripcion"
cat > "${sudoers}.nuevo" <<SUDO
# El API pide tokens de inscripcion a step-ca sin tener la contrasena de la
# CA. La regla se limita a este unico comando.
jocotoco ALL=(root) NOPASSWD: /usr/local/sbin/jocotoco-emitir-token
SUDO
chmod 0440 "${sudoers}.nuevo"

if visudo -c -f "${sudoers}.nuevo" >/dev/null 2>&1; then
    mv "${sudoers}.nuevo" "$sudoers"
else
    rm -f "${sudoers}.nuevo"
    echo "ERROR: la regla de sudo no es valida; no se instalo nada." >&2
    exit 1
fi

# --- 2. php-fpm: proc_open y las variables de la inscripcion ------------
echo "==> Ajustando el pool de php-fpm"
cp -a "$POOL" "${POOL}.$(date +%Y%m%d-%H%M%S).bak"

# proc_open es necesario para invocar el envoltorio; el resto de funciones
# de ejecucion siguen deshabilitadas.
sed -i 's/^\(php_admin_value\[disable_functions\] = \).*/\1exec,passthru,shell_exec,system,popen/' "$POOL"

huella="$(step certificate fingerprint "$STEPPATH/certs/root_ca.crt" 2>/dev/null || echo '')"
ca_dns="$(jq -r '.dnsNames | last' "$STEPPATH/config/ca.json" 2>/dev/null || echo '')"
ca_puerto="$(jq -r '.address' "$STEPPATH/config/ca.json" 2>/dev/null | sed 's/^.*://' || echo 8443)"

poner_env() {
    local clave="$1" valor="$2"
    sed -i "/^env\[${clave}\]/d" "$POOL"
    printf 'env[%s] = %s\n' "$clave" "$valor" >> "$POOL"
}

poner_env JOCOTOCO_ENROLLMENT_ENABLED true
poner_env JOCOTOCO_ENROLLMENT_SECRET "\"${SECRETO}\""
poner_env JOCOTOCO_ENROLLMENT_MAX_TOKENS "$MAX_TOKENS"
poner_env JOCOTOCO_ENROLLMENT_TOKEN_DURATION 60m
poner_env JOCOTOCO_CA_URL "https://${ca_dns}:${ca_puerto}"
poner_env JOCOTOCO_CA_ROOT_FINGERPRINT "$huella"
[[ -n "$DISPOSITIVOS" ]] && poner_env JOCOTOCO_ENROLLMENT_DEVICES "\"${DISPOSITIVOS}\""

chmod 0640 "$POOL"
systemctl restart php8.3-fpm

echo "==> Aplicando migraciones (tabla de inscripciones)"
runuser -u jocotoco -- env JOCOTOCO_DATA_DIR=/var/lib/jocotoco php "$DESTINO/bin/jocotoco" migrate

# --- 3. Apache: excepcion de mTLS para la ruta -------------------------
if ! grep -q '/v1/inscripcion' /etc/apache2/sites-available/jocotoco-api.conf 2>/dev/null; then
    echo "ERROR: el VirtualHost instalado no tiene la excepcion para /v1/inscripcion." >&2
    echo "       Regenerelo y vuelva a ejecutar este script:" >&2
    echo "         sudo ./05-activar-api.sh --dominio ${DOMINIO} --puerto ${PUERTO}" >&2
    exit 1
fi

# --- 4. Verificacion de extremo a extremo -------------------------------
echo "==> Comprobando el flujo completo con un dispositivo de prueba"
prueba="prueba-inscripcion.invalid"
raiz="/etc/step/certs/root_ca.crt"

respuesta="$(curl -sS --noproxy '*' --resolve "${DOMINIO}:${PUERTO}:127.0.0.1" --cacert "$raiz" \
    -X POST "https://${DOMINIO}:${PUERTO}/v1/inscripcion" \
    -H 'Content-Type: application/json' \
    -d "{\"device_id\":\"${prueba}\",\"factory_secret\":\"${SECRETO}\"}" \
    -w '\n%{http_code}' 2>&1 || echo $'\n000')"

codigo="$(tail -n1 <<<"$respuesta")"
cuerpo="$(sed '$d' <<<"$respuesta")"

if [[ "$codigo" != "200" ]]; then
    echo "ERROR: la prueba devolvio HTTP ${codigo}:" >&2
    echo "$cuerpo" >&2
    echo "       Revise: tail -5 /var/log/jocotoco/api.log" >&2
    echo "               tail -5 /var/log/apache2/jocotoco-api.error.log" >&2
    exit 1
fi

if ! grep -q '"token"' <<<"$cuerpo"; then
    echo "ERROR: la respuesta no contiene un token:" >&2
    echo "$cuerpo" >&2
    exit 1
fi

echo "    Token de prueba emitido correctamente."
runuser -u jocotoco -- env JOCOTOCO_DATA_DIR=/var/lib/jocotoco \
    php "$DESTINO/bin/jocotoco" bloquear-inscripcion "$prueba" 'verificacion del instalador'

cat <<RESUMEN

===========================================================================
 Alta automatica habilitada en:
   POST https://${DOMINIO}:${PUERTO}/v1/inscripcion

 Cuerpo:    {"device_id": "rpi-yanacocha-01", "factory_secret": "<secreto>"}
 Respuesta: {"token": "...", "ca_url": "https://${ca_dns}:${ca_puerto}",
             "huella_raiz": "${huella}", "tokens_restantes": N}

 Secreto de fabrica: ${SECRETO}
   (esta en ${POOL}; el mismo valor va en el codigo del dispositivo)

 Limites: ${MAX_TOKENS} tokens por dispositivo, 10 intentos fallidos por IP
          por hora, nombres de hostname en minusculas.
 $( [[ -n "$DISPOSITIVOS" ]] && echo "Lista de dispositivos autorizados: ${DISPOSITIVOS}" )

 Operacion:
   sudo -u jocotoco php ${DESTINO}/bin/jocotoco inscripciones
   sudo -u jocotoco php ${DESTINO}/bin/jocotoco bloquear-inscripcion <disp>

 Para deshabilitarla:
   sudo sed -i 's/^env\[JOCOTOCO_ENROLLMENT_ENABLED\].*/env[JOCOTOCO_ENROLLMENT_ENABLED] = false/' ${POOL}
   sudo systemctl reload php8.3-fpm
===========================================================================
RESUMEN
