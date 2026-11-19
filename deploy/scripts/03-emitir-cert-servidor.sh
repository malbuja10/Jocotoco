#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# Emite (y programa la renovacion de) el certificado TLS del servidor del
# API usando step-ca. Tambien copia la raiz de la CA donde Apache la espera
# para validar los certificados de las Raspberry.
#
# Uso: sudo ./03-emitir-cert-servidor.sh \
#          --dominio api.jocotoco.local \
#          --ca-url https://ca.jocotoco.local:8443 \
#          --huella <HUELLA_DE_LA_RAIZ>
#
# Opciones: --vigencia 2160h       (lo que se le pide a la CA)
#           --renovar-cada 30      (cada cuantos dias se renueva)
#
# Si step-ca corre en esta misma maquina puede omitir --ca-url/--huella:
# se toman de /etc/step.
# ---------------------------------------------------------------------------
set -euo pipefail

DOMINIO="api.jocotoco.local"
CA_URL=""
HUELLA=""
AQUI="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=lib-politica.sh
source "$AQUI/lib-politica.sh"
politica_leer

DESTINO_TLS="/etc/jocotoco/tls"
RAIZ_CA="/etc/step/certs/root_ca.crt"
# 90 dias, igual que los certificados de dispositivo. La CA recorta este
# valor a su maximo si es menor (una CA recien instalada por un 01 antiguo
# emite 24 h): lo que se use de verdad se lee despues del certificado.
# Los dos numeros salen de la politica (07-vigencia-dispositivos.sh los
# escribe); --vigencia y --renovar-cada los sobreescriben para un caso
# puntual.
VIGENCIA="$(( POLITICA_VIGENCIA_DIAS * 24 ))h"
RENOVAR_CADA_DIAS="$POLITICA_RENOVAR_CADA_DIAS"

while [[ $# -gt 0 ]]; do
    case "$1" in
        --dominio) DOMINIO="$2"; shift 2 ;;
        --ca-url) CA_URL="$2"; shift 2 ;;
        --huella) HUELLA="$2"; shift 2 ;;
        --vigencia) VIGENCIA="$2"; shift 2 ;;
        --renovar-cada) RENOVAR_CADA_DIAS="$2"; shift 2 ;;
        *) echo "Parametro desconocido: $1" >&2; exit 1 ;;
    esac
done

[[ $EUID -eq 0 ]] || { echo "Ejecute este script con sudo." >&2; exit 1; }
[[ "$RENOVAR_CADA_DIAS" =~ ^[0-9]+$ ]] && (( RENOVAR_CADA_DIAS >= 1 )) \
    || { echo "--renovar-cada debe ser un entero de dias >= 1." >&2; exit 1; }
command -v step >/dev/null 2>&1 || { echo "Falta step-cli. Ejecute 01-instalar-step-ca.sh o instale step-cli." >&2; exit 1; }

# El certificado y su renovacion automatica usan siempre este STEPPATH,
# tambien cuando la CA corre en esta misma maquina: jocotoco-cert-renew.service
# lo necesita inicializado para poder ejecutar "step ca renew".
export STEPPATH="${STEPPATH:-/root/.step}"

if [[ -z "$CA_URL" || -z "$HUELLA" ]]; then
    if [[ -f /etc/step/config/ca.json ]]; then
        command -v jq >/dev/null 2>&1 || { echo "Falta jq: sudo apt-get install -y jq" >&2; exit 1; }
        echo "==> Tomando los datos de la CA local de /etc/step"
        # "address" en ca.json ya viene como ":8443".
        CA_URL="${CA_URL:-https://$(jq -r '.dnsNames[0]' /etc/step/config/ca.json)$(jq -r '.address' /etc/step/config/ca.json)}"
        HUELLA="${HUELLA:-$(step certificate fingerprint /etc/step/certs/root_ca.crt)}"
    else
        echo "Indique --ca-url y --huella, o instale step-ca en esta maquina." >&2
        exit 1
    fi
fi

# El nombre de la CA tiene que resolver desde aqui: step valida el
# certificado TLS de la CA contra ese nombre, asi que no sirve apuntar a
# 127.0.0.1. Si la CA corre en esta misma maquina, basta una linea en
# /etc/hosts.
ca_host="${CA_URL#*://}"
ca_host="${ca_host%%:*}"
if ! getent hosts "$ca_host" >/dev/null 2>&1; then
    echo "ERROR: el nombre ${ca_host} no resuelve en este servidor." >&2
    echo "       step valida el certificado de la CA contra ese nombre." >&2
    echo "       Si la CA corre aqui mismo, agreguelo a /etc/hosts:" >&2
    echo "         echo '127.0.0.1 ${ca_host}' >> /etc/hosts" >&2
    echo "       Si corre en otra maquina, publique el nombre en su DNS." >&2
    exit 1
fi

echo "==> Confiando en la CA ${CA_URL}"
step ca bootstrap --ca-url "$CA_URL" --fingerprint "$HUELLA" --force
RAIZ_CA="$STEPPATH/certs/root_ca.crt"

if ! step ca health --ca-url "$CA_URL" --root "$RAIZ_CA" >/dev/null 2>&1; then
    echo "ERROR: la CA no responde en ${CA_URL}." >&2
    echo "       Revise:  systemctl status step-ca" >&2
    echo "                ss -ltnp | grep 8443" >&2
    exit 1
fi

install -d -o root -g jocotoco -m 0750 "$DESTINO_TLS"

# El provisioner JWK pide la contrasena de su llave para emitir el token.
# Si la CA es local, es la misma que quedo en /etc/step/password.txt y se
# pasa por archivo para no tener que teclearla; si no, step la pregunta.
CLAVE_PROVISIONER=()
if [[ -r /etc/step/password.txt ]]; then
    CLAVE_PROVISIONER=(--provisioner-password-file /etc/step/password.txt)
    echo "    (usando la contrasena del provisioner de /etc/step/password.txt)"
fi

echo "==> Solicitando el certificado del servidor para ${DOMINIO}"
step ca certificate "$DOMINIO" \
    "$DESTINO_TLS/servidor.crt" "$DESTINO_TLS/servidor.key" \
    --san "$DOMINIO" \
    --not-after "$VIGENCIA" \
    "${CLAVE_PROVISIONER[@]}" \
    --force

chown root:jocotoco "$DESTINO_TLS/servidor.crt" "$DESTINO_TLS/servidor.key"
chmod 0640 "$DESTINO_TLS/servidor.crt"
chmod 0640 "$DESTINO_TLS/servidor.key"

echo "==> Publicando la raiz de la CA para la verificacion de clientes"
install -d -m 0755 /etc/step/certs
install -m 0644 "$RAIZ_CA" /etc/step/certs/root_ca.crt

# El umbral de renovacion se calcula sobre el certificado REALMENTE emitido,
# no sobre lo pedido: si la CA tiene un maximo mas corto, recorta sin avisar.
vigencia_segundos=$(( $(date -d "$(openssl x509 -in "$DESTINO_TLS/servidor.crt" -noout -enddate | cut -d= -f2)" +%s) \
                    - $(date -d "$(openssl x509 -in "$DESTINO_TLS/servidor.crt" -noout -startdate | cut -d= -f2)" +%s) ))
vigencia_dias=$(( vigencia_segundos / 86400 ))
renovar_antes_horas=$(( (vigencia_segundos - RENOVAR_CADA_DIAS * 86400) / 3600 ))

if (( renovar_antes_horas < 8 )); then
    renovar_antes_horas=$(( vigencia_segundos / 3600 / 3 ))
    (( renovar_antes_horas < 8 )) && renovar_antes_horas=8
    echo "AVISO: la CA emitio un certificado de ${vigencia_dias} dia(s), menos que los" >&2
    echo "       ${RENOVAR_CADA_DIAS} dias pedidos entre renovaciones. Se renovara cuando le" >&2
    echo "       queden ${renovar_antes_horas} h. Para ampliar la vigencia de la CA:" >&2
    echo "         sudo ./07-vigencia-dispositivos.sh --dias 90" >&2
    echo "       y vuelva a ejecutar este script." >&2
else
    echo "==> Certificado de ${vigencia_dias} dias; se renovara cada ${RENOVAR_CADA_DIAS} dias"
fi

install -d -o root -g jocotoco -m 0750 /etc/jocotoco
cat > /etc/jocotoco/cert-renew.env <<ENV
# Lo escribe 03-emitir-cert-servidor.sh. Umbral de "step ca renew": el
# certificado se cambia cuando le queda menos de esto, lo que equivale a
# renovarlo cada ${RENOVAR_CADA_DIAS} dias (vigencia de ${vigencia_dias} dias).
JOCOTOCO_RENOVAR_ANTES=${renovar_antes_horas}h
ENV
chmod 0644 /etc/jocotoco/cert-renew.env

echo "==> Instalando la renovacion automatica (systemd timer)"
install -m 0644 "$(dirname "$0")/../systemd/jocotoco-cert-renew.service" /etc/systemd/system/
install -m 0644 "$(dirname "$0")/../systemd/jocotoco-cert-renew.timer" /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now jocotoco-cert-renew.timer

echo "==> Habilitando el sitio del API"
if [[ ! -f /etc/apache2/sites-available/jocotoco-api.conf ]]; then
    echo "ERROR: falta /etc/apache2/sites-available/jocotoco-api.conf; ejecute antes 02-instalar-api.sh." >&2
    exit 1
fi

# El puerto lo fijo 02 en el VirtualHost; puede no ser el 443 si el servidor
# ya tenia otro sitio ahi.
PUERTO_API="$(grep -oP '^<VirtualHost \*:\K[0-9]+' /etc/apache2/sites-available/jocotoco-api.conf 2>/dev/null | tail -1 || true)"
PUERTO_API="${PUERTO_API:-443}"

# Si otro proceso ajeno a Apache ya escucha ese puerto, Apache no arrancara y
# el error de systemd no lo dice con claridad.
if command -v ss >/dev/null 2>&1; then
    ocupa="$(ss -ltnpH 2>/dev/null | awk -v p=":${PUERTO_API}\$" '$4 ~ p {print $6}' | head -1 || true)"
    if [[ -n "$ocupa" && "$ocupa" != *apache2* ]]; then
        # Fatal a proposito, y antes de habilitar el sitio: si se habilita
        # un VirtualHost cuyo puerto esta ocupado, Apache no arranca y se
        # queda sin servir tampoco los demas sitios.
        echo "ERROR: el puerto ${PUERTO_API} del API ya lo escucha otro proceso:" >&2
        echo "         ${ocupa}" >&2
        echo "       El certificado ya quedo emitido; solo falta elegir puerto." >&2
        echo "       Reinstale el sitio en un puerto libre y repita este paso:" >&2
        echo "         sudo ./02-instalar-api.sh --dominio ${DOMINIO} --puerto <LIBRE>" >&2
        echo "       Puertos en uso ahora mismo:" >&2
        ss -ltnH 2>/dev/null | awk '{print $4}' | grep -oE '[0-9]+$' | sort -un | tr '\n' ' ' >&2
        echo >&2
        exit 1
    fi
fi

a2ensite -q jocotoco-api

# El sitio por defecto de Apache solo se aparta cuando este servidor es
# exclusivo del API. Si ya sirve otros vhosts, no se toca nada: podria ser
# el que atiende ese trafico.
otros="$(ls -1 /etc/apache2/sites-enabled/ 2>/dev/null \
    | grep -vE '^jocotoco-api|^000-default|^default-ssl' || true)"
if [[ -z "$otros" ]]; then
    a2dissite -q 000-default default-ssl 2>/dev/null || true
else
    echo "    (se conservan los sitios ya habilitados: $(echo $otros | tr '\n' ' '))"
fi

apache2ctl configtest

# "systemctl reload" falla si el servicio no estaba corriendo: 02 configura
# Apache pero no lo arranca, porque hasta ahora faltaban los certificados.
if systemctl is-active --quiet apache2; then
    accion=reload
else
    accion=start
fi

echo "==> Apache: ${accion}"
if ! systemctl "$accion" apache2 || ! systemctl is-active --quiet apache2; then
    echo "ERROR: Apache no pudo ${accion}. La sintaxis es valida, asi que el" >&2
    echo "       problema es de arranque. Revise, en este orden:" >&2
    echo "         journalctl -xeu apache2.service --no-pager | tail -30" >&2
    echo "         systemctl status apache2.service --no-pager | tail -20" >&2
    echo "         ss -ltnp | grep -E ':(80|443)'    # puerto ocupado" >&2
    echo "         apache2ctl -S                     # vhosts y conflictos de ServerName" >&2
    echo "       El sitio queda habilitado; corrija y ejecute: systemctl start apache2" >&2
    exit 1
fi

cat <<RESUMEN

===========================================================================
 Certificado del servidor emitido.

   Hoja  : ${DESTINO_TLS}/servidor.crt
   Llave : ${DESTINO_TLS}/servidor.key
   Raiz  : /etc/step/certs/root_ca.crt  (Apache valida clientes con esta)

 Renovacion: jocotoco-cert-renew.timer comprueba cada 8 h y renueva cada
             ${RENOVAR_CADA_DIAS} dias (umbral ${renovar_antes_horas} h, en
             /etc/jocotoco/cert-renew.env); recarga Apache al cambiarlo.
 Verifique:  curl -sk https://${DOMINIO}:${PUERTO_API}/v1/salud | jq
===========================================================================
RESUMEN
