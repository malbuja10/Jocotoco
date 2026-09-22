#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# Prepara una Raspberry Pi para enviar audio al API:
#   1. instala step-cli,
#   2. confia en la raiz de la CA (step ca bootstrap),
#   3. obtiene su primer certificado con el token de un solo uso,
#   4. instala la renovacion automatica (systemd timer).
#
# Uso (como root en la Raspberry):
#   sudo ./bootstrap-dispositivo.sh \
#        --nombre rpi-yanacocha-01 \
#        --ca-url https://ca.jocotoco.local:8443 \
#        --huella <HUELLA_RAIZ> \
#        --token '<TOKEN>'
#
# El token lo genera el servidor con deploy/scripts/04-registrar-dispositivo.sh
# ---------------------------------------------------------------------------
set -euo pipefail

VERSION_STEP="${VERSION_STEP:-0.28.2}"
NOMBRE=""
CA_URL=""
HUELLA=""
TOKEN=""
API_URL="https://api.jocotoco.local"
DIR="/etc/jocotoco"
VIGENCIA="24h"

while [[ $# -gt 0 ]]; do
    case "$1" in
        --nombre) NOMBRE="$2"; shift 2 ;;
        --ca-url) CA_URL="$2"; shift 2 ;;
        --huella) HUELLA="$2"; shift 2 ;;
        --token) TOKEN="$2"; shift 2 ;;
        --api-url) API_URL="$2"; shift 2 ;;
        --vigencia) VIGENCIA="$2"; shift 2 ;;
        *) echo "Parametro desconocido: $1" >&2; exit 1 ;;
    esac
done

[[ $EUID -eq 0 ]] || { echo "Ejecute este script con sudo." >&2; exit 1; }
for requerido in NOMBRE CA_URL HUELLA TOKEN; do
    [[ -n "${!requerido}" ]] || { echo "Falta --${requerido,,}" >&2; exit 1; }
done

echo "==> Verificando el reloj del sistema"
if ! timedatectl show --property=NTPSynchronized --value | grep -q '^yes$'; then
    echo "    ADVERTENCIA: el reloj no esta sincronizado por NTP."
    echo "    Un reloj desfasado invalida el certificado. Ejecute:"
    echo "      sudo timedatectl set-ntp true"
fi

echo "==> Instalando dependencias"
apt-get update -qq
apt-get install -y -qq curl ca-certificates jq

if ! command -v step >/dev/null 2>&1; then
    arquitectura="$(dpkg --print-architecture)"   # arm64 en Raspberry Pi OS 64 bit
    paquete="$(mktemp --suffix=.deb)"
    echo "==> Instalando step-cli v${VERSION_STEP} (${arquitectura})"
    curl -fsSL "https://github.com/smallstep/cli/releases/download/v${VERSION_STEP}/step-cli_${VERSION_STEP}_${arquitectura}.deb" -o "$paquete"
    dpkg -i "$paquete"
    rm -f "$paquete"
fi

echo "==> Creando ${DIR}"
install -d -m 0755 "$DIR"
install -d -m 0700 "$DIR/tls"
install -d -m 0755 /var/lib/jocotoco/cola     # grabaciones pendientes de envio
install -d -m 0755 /var/lib/jocotoco/enviadas

export STEPPATH="$DIR/step"
install -d -m 0700 "$STEPPATH"

echo "==> Confiando en la CA ${CA_URL}"
step ca bootstrap --ca-url "$CA_URL" --fingerprint "$HUELLA" --force

echo "==> Solicitando el certificado de ${NOMBRE}"
step ca certificate "$NOMBRE" \
    "$DIR/tls/dispositivo.crt" "$DIR/tls/dispositivo.key" \
    --token "$TOKEN" \
    --not-after "$VIGENCIA" \
    --force

chmod 0644 "$DIR/tls/dispositivo.crt"
chmod 0600 "$DIR/tls/dispositivo.key"
install -m 0644 "$STEPPATH/certs/root_ca.crt" "$DIR/tls/raiz-ca.crt"

echo "==> Guardando la configuracion del cliente"
cat > "$DIR/dispositivo.env" <<ENV
# Configuracion del cliente de audio Jocotoco (generada por bootstrap).
JOCOTOCO_API_URL=${API_URL}
JOCOTOCO_DISPOSITIVO=${NOMBRE}
JOCOTOCO_CERT=${DIR}/tls/dispositivo.crt
JOCOTOCO_KEY=${DIR}/tls/dispositivo.key
JOCOTOCO_CA=${DIR}/tls/raiz-ca.crt
JOCOTOCO_COLA=/var/lib/jocotoco/cola
JOCOTOCO_ENVIADAS=/var/lib/jocotoco/enviadas
STEPPATH=${STEPPATH}
ENV
chmod 0644 "$DIR/dispositivo.env"

echo "==> Instalando scripts y temporizadores"
origen="$(cd "$(dirname "$0")" && pwd)"
install -m 0755 "$origen/enviar-audio.sh" /usr/local/bin/jocotoco-enviar-audio
install -m 0755 "$origen/grabar-audio.sh" /usr/local/bin/jocotoco-grabar-audio
install -m 0644 "$origen/systemd/jocotoco-renovar-cert.service" /etc/systemd/system/
install -m 0644 "$origen/systemd/jocotoco-renovar-cert.timer" /etc/systemd/system/
install -m 0644 "$origen/systemd/jocotoco-enviar-cola.service" /etc/systemd/system/
install -m 0644 "$origen/systemd/jocotoco-enviar-cola.timer" /etc/systemd/system/

systemctl daemon-reload
systemctl enable --now jocotoco-renovar-cert.timer
systemctl enable --now jocotoco-enviar-cola.timer

echo "==> Comprobando el acceso al API"
if curl -fsS --cert "$DIR/tls/dispositivo.crt" --key "$DIR/tls/dispositivo.key" \
        --cacert "$DIR/tls/raiz-ca.crt" "$API_URL/v1/yo" | jq -r '.dispositivo, .expira_en_segundos'; then
    echo "==> El API reconoce a este dispositivo."
else
    echo "    No se pudo consultar ${API_URL}/v1/yo. Revise DNS, firewall y la lista blanca del API." >&2
fi

cat <<RESUMEN

===========================================================================
 Dispositivo ${NOMBRE} listo.

 Certificado : ${DIR}/tls/dispositivo.crt  (vigencia ${VIGENCIA})
 Renovacion  : jocotoco-renovar-cert.timer (cada 4 h)
 Cola        : /var/lib/jocotoco/cola  ->  jocotoco-enviar-cola.timer

 Enviar una grabacion ahora:
   jocotoco-enviar-audio /ruta/grabacion.wav --sitio "Reserva Yanacocha"

 Grabar 60 s y encolar:
   jocotoco-grabar-audio --segundos 60
===========================================================================
RESUMEN
