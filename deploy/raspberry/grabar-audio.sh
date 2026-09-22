#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# Graba audio del microfono de la Raspberry Pi y lo deja en la cola de
# envio. El envio real lo hace jocotoco-enviar-audio (temporizador), de
# modo que una caida de red no interrumpe la grabacion.
#
# Uso:
#   jocotoco-grabar-audio [--segundos 60] [--dispositivo-alsa plughw:1,0]
#                         [--frecuencia 48000] [--canales 1] [--enviar]
#
# Requiere alsa-utils:  sudo apt-get install -y alsa-utils
# Liste sus microfonos:  arecord -l
# ---------------------------------------------------------------------------
set -euo pipefail

CONFIG="${JOCOTOCO_CONFIG:-/etc/jocotoco/dispositivo.env}"

# Carga /etc/jocotoco/dispositivo.env dando prioridad a las variables que
# ya vienen del entorno (systemd, linea de comandos, pruebas).
cargar_config() {
    local archivo="$1" linea clave valor
    [[ -r "$archivo" ]] || return 0

    while IFS= read -r linea || [[ -n "$linea" ]]; do
        [[ "$linea" =~ ^[[:space:]]*# ]] && continue
        [[ "$linea" == *=* ]] || continue

        clave="${linea%%=*}"
        valor="${linea#*=}"
        clave="${clave//[[:space:]]/}"
        [[ "$clave" =~ ^[A-Za-z_][A-Za-z0-9_]*$ ]] || continue

        valor="${valor#"${valor%%[![:space:]]*}"}"
        valor="${valor%"${valor##*[![:space:]]}"}"
        valor="${valor%\"}" ; valor="${valor#\"}"
        valor="${valor%\'}" ; valor="${valor#\'}"

        [[ -n "${!clave-}" ]] && continue
        export "$clave=$valor"
    done < "$archivo"
}

cargar_config "$CONFIG"

COLA="${JOCOTOCO_COLA:-/var/lib/jocotoco/cola}"
NOMBRE="${JOCOTOCO_DISPOSITIVO:-$(hostname)}"
SEGUNDOS=60
ALSA="${JOCOTOCO_ALSA:-default}"
FRECUENCIA=48000
CANALES=1
FORMATO="S16_LE"
ENVIAR=0

while [[ $# -gt 0 ]]; do
    case "$1" in
        --segundos) SEGUNDOS="$2"; shift 2 ;;
        --dispositivo-alsa) ALSA="$2"; shift 2 ;;
        --frecuencia) FRECUENCIA="$2"; shift 2 ;;
        --canales) CANALES="$2"; shift 2 ;;
        --enviar) ENVIAR=1; shift ;;
        -h|--help) sed -n '2,16p' "$0"; exit 0 ;;
        *) echo "Parametro desconocido: $1" >&2; exit 1 ;;
    esac
done

command -v arecord >/dev/null 2>&1 || { echo "Falta arecord: sudo apt-get install -y alsa-utils" >&2; exit 1; }
install -d -m 0755 "$COLA"

marca="$(date -u +%Y%m%dT%H%M%SZ)"
destino="${COLA}/${NOMBRE}-${marca}.wav"
temporal="${destino}.parcial"

echo "==> Grabando ${SEGUNDOS}s desde ${ALSA} (${FRECUENCIA} Hz, ${CANALES} canal/es)"
arecord \
    --device="$ALSA" \
    --format="$FORMATO" \
    --rate="$FRECUENCIA" \
    --channels="$CANALES" \
    --duration="$SEGUNDOS" \
    --file-type=wav \
    "$temporal"

# El archivo aparece en la cola solo cuando esta completo: el temporizador
# de envio ignora los ".parcial".
mv -f "$temporal" "$destino"
echo "==> Grabacion lista: ${destino}"

if (( ENVIAR )); then
    exec jocotoco-enviar-audio "$destino" --duracion "$SEGUNDOS"
fi
