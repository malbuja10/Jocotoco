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

# Version de respaldo si la API de GitHub no responde; el script resuelve
# primero la ultima publicada. Para fijarla: VERSION_STEP=0.30.6 ./bootstrap...
VERSION_STEP="${VERSION_STEP:-0.30.6}"
NOMBRE=""
CA_URL=""
HUELLA=""
TOKEN=""
API_URL="https://api.jocotoco.local"
DIR="/etc/jocotoco"
# Vacio = la vigencia que la CA tenga configurada por defecto. Para equipos
# que pasan semanas sin conexion, ampliela en el servidor con
# 07-vigencia-dispositivos.sh en lugar de forzarla aqui.
VIGENCIA=""
# Cada cuantos dias se renueva el certificado. No es la frecuencia con que
# corre el temporizador (eso es cada 4 h), sino la edad a la que el
# certificado se cambia: el umbral que se le pasa a "step ca renew" es
# "vigencia - estos dias".
RENOVAR_CADA_DIAS=30

while [[ $# -gt 0 ]]; do
    case "$1" in
        --nombre) NOMBRE="$2"; shift 2 ;;
        --ca-url) CA_URL="$2"; shift 2 ;;
        --huella) HUELLA="$2"; shift 2 ;;
        --token) TOKEN="$2"; shift 2 ;;
        --api-url) API_URL="$2"; shift 2 ;;
        --renovar-cada) RENOVAR_CADA_DIAS="$2"; shift 2 ;;
        --vigencia) VIGENCIA="$2"; shift 2 ;;
        *) echo "Parametro desconocido: $1" >&2; exit 1 ;;
    esac
done

[[ $EUID -eq 0 ]] || { echo "Ejecute este script con sudo." >&2; exit 1; }
for requerido in NOMBRE CA_URL HUELLA TOKEN; do
    [[ -n "${!requerido}" ]] || { echo "Falta --${requerido,,}" >&2; exit 1; }
done
[[ "$RENOVAR_CADA_DIAS" =~ ^[0-9]+$ ]] && (( RENOVAR_CADA_DIAS >= 1 )) \
    || { echo "--renovar-cada debe ser un entero de dias >= 1." >&2; exit 1; }

echo "==> Verificando el reloj del sistema"
if ! timedatectl show --property=NTPSynchronized --value | grep -q '^yes$'; then
    echo "    ADVERTENCIA: el reloj no esta sincronizado por NTP."
    echo "    Un reloj desfasado invalida el certificado. Ejecute:"
    echo "      sudo timedatectl set-ntp true"
fi

echo "==> Instalando dependencias"
apt-get update -qq
apt-get install -y -qq curl ca-certificates jq

# No basta con que el binario exista: una descarga a medias o un binario de
# otra arquitectura tambien "existe" y falla con un error confuso del shell.
if command -v step >/dev/null 2>&1 && step version >/dev/null 2>&1; then
    echo "==> step-cli ya instalado: $(step version | head -1)"
else
    if command -v step >/dev/null 2>&1; then
        echo "    ADVERTENCIA: $(command -v step) existe pero no responde a 'step version'."
        echo "    Apartelo (mv $(command -v step) $(command -v step).roto) si el problema persiste."
    fi

    arquitectura="$(dpkg --print-architecture)"   # arm64 en Raspberry Pi OS 64 bit
    url="$(curl -fsSL --max-time 15 https://api.github.com/repos/smallstep/cli/releases/latest 2>/dev/null \
        | jq -r --arg sufijo "_${arquitectura}.deb" \
            '[.assets[]? | select(.name | endswith($sufijo)) | .browser_download_url] | first // empty' \
            2>/dev/null || true)"
    url="${url:-https://github.com/smallstep/cli/releases/download/v${VERSION_STEP}/step-cli_${VERSION_STEP}_${arquitectura}.deb}"

    paquete="$(mktemp --suffix=.deb)"
    echo "==> Instalando step-cli (${arquitectura}) desde ${url}"
    if ! curl -fSL --retry 3 "$url" -o "$paquete"; then
        rm -f "$paquete"
        echo "ERROR: no se pudo descargar step-cli. Versiones disponibles:" >&2
        echo "       https://github.com/smallstep/cli/releases" >&2
        exit 1
    fi

    if ! dpkg-deb --info "$paquete" >/dev/null 2>&1; then
        rm -f "$paquete"
        echo "ERROR: lo descargado no es un .deb valido: ${url}" >&2
        exit 1
    fi

    dpkg -i "$paquete" || apt-get install -y -f
    rm -f "$paquete"
    hash -r

    if ! step version >/dev/null 2>&1; then
        echo "ERROR: step-cli sigue sin funcionar tras instalar el paquete." >&2
        echo "       Revise si otro binario lo tapa: command -v -a step" >&2
        exit 1
    fi
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
VIGENCIA_ARGS=()
[[ -n "$VIGENCIA" ]] && VIGENCIA_ARGS=(--not-after "$VIGENCIA")

step ca certificate "$NOMBRE" \
    "$DIR/tls/dispositivo.crt" "$DIR/tls/dispositivo.key" \
    --token "$TOKEN" \
    "${VIGENCIA_ARGS[@]}" \
    --force

chmod 0644 "$DIR/tls/dispositivo.crt"
chmod 0600 "$DIR/tls/dispositivo.key"
install -m 0644 "$STEPPATH/certs/root_ca.crt" "$DIR/tls/raiz-ca.crt"

# Cada cuanto se cambia el certificado. "step ca renew" no renueva por
# calendario sino por lo que le quede de vida, asi que para que el cambio
# ocurra cada N dias el umbral tiene que ser "vigencia - N dias": con 90
# dias de vigencia y N=30, se renueva cuando le quedan 60, o sea al mes.
#
# El temporizador sigue corriendo cada 4 h a proposito: esos intentos no
# renuevan nada mientras no toque, y son los que permiten que un equipo que
# estuvo semanas sin enlace se ponga al dia en su primera ventana de
# conexion.
vigencia_segundos=$(( $(date -d "$(openssl x509 -in "$DIR/tls/dispositivo.crt" -noout -enddate | cut -d= -f2)" +%s) \
                    - $(date -d "$(openssl x509 -in "$DIR/tls/dispositivo.crt" -noout -startdate | cut -d= -f2)" +%s) ))
vigencia_dias=$(( vigencia_segundos / 86400 ))
renovar_antes_horas=$(( (vigencia_segundos - RENOVAR_CADA_DIAS * 86400) / 3600 ))

if (( renovar_antes_horas < 8 )); then
    # El certificado no vive lo suficiente para cambiarlo cada N dias (por
    # ejemplo, los 24 h de una CA recien instalada): se vuelve al tercio de
    # la vida, que es lo seguro.
    renovar_antes_horas=$(( vigencia_segundos / 3600 / 3 ))
    (( renovar_antes_horas < 8 )) && renovar_antes_horas=8
    echo "AVISO: la vigencia es de ${vigencia_dias} dias, menor que los ${RENOVAR_CADA_DIAS} dias" >&2
    echo "       pedidos entre renovaciones. Se renovara cuando le queden" >&2
    echo "       ${renovar_antes_horas} h. Para ampliarla, en el servidor:" >&2
    echo "         sudo ./07-vigencia-dispositivos.sh --dias 90" >&2
else
    echo "==> Certificado de ${vigencia_dias} dias; se renovara cada ${RENOVAR_CADA_DIAS} dias"
    echo "    (umbral: cuando le queden ${renovar_antes_horas} h; el temporizador comprueba cada 4 h)"
fi

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
# Umbral de renovacion (lo usa jocotoco-renovar-cert.service): el
# certificado se cambia cuando le queda menos de esto, lo que equivale a
# renovarlo cada ${RENOVAR_CADA_DIAS} dias.
JOCOTOCO_RENOVAR_ANTES=${renovar_antes_horas}h
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

 Certificado : ${DIR}/tls/dispositivo.crt  ($(( vigencia_segundos / 86400 )) dias)
 Renovacion  : jocotoco-renovar-cert.timer (revisa cada 4 h; renueva cuando
               queden menos de ${renovar_antes_horas} h, asi que basta con
               que el equipo tenga conexion alguna vez en ese margen)
 Cola        : /var/lib/jocotoco/cola  ->  jocotoco-enviar-cola.timer

 Enviar una grabacion ahora:
   jocotoco-enviar-audio /ruta/grabacion.wav --sitio "Reserva Yanacocha"

 Grabar 60 s y encolar:
   jocotoco-grabar-audio --segundos 60
===========================================================================
RESUMEN
