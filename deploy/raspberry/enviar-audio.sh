#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# Envia una grabacion al API de audio usando el certificado emitido por
# step-ca (mTLS). Si el envio falla, el archivo queda en la cola para el
# siguiente intento del temporizador.
#
# Uso:
#   jocotoco-enviar-audio grabacion.wav [--sitio "Reserva Yanacocha"]
#                                       [--estacion E-07]
#                                       [--grabado-en 2026-09-22T05:30:00Z]
#                                       [--nota "lluvia ligera"]
#                                       [--mantener]
#   jocotoco-enviar-audio --cola        # procesa /var/lib/jocotoco/cola
#
# Salidas: 0 enviado (o duplicado ya existente), 1 error permanente
#          (el API rechazo el archivo), 2 error temporal (red/servidor).
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

API_URL="${JOCOTOCO_API_URL:-https://api.jocotoco.local}"
CERT="${JOCOTOCO_CERT:-/etc/jocotoco/tls/dispositivo.crt}"
KEY="${JOCOTOCO_KEY:-/etc/jocotoco/tls/dispositivo.key}"
CA="${JOCOTOCO_CA:-/etc/jocotoco/tls/raiz-ca.crt}"
COLA="${JOCOTOCO_COLA:-/var/lib/jocotoco/cola}"
ENVIADAS="${JOCOTOCO_ENVIADAS:-/var/lib/jocotoco/enviadas}"
REINTENTOS="${JOCOTOCO_REINTENTOS:-3}"
# step-cli necesita saber donde vive la configuracion de la CA para renovar.
export STEPPATH="${STEPPATH:-/etc/jocotoco/step}"

SITIO="" ; ESTACION="" ; NOTA="" ; GRABADO_EN="" ; DURACION="" ; MANTENER=0 ; MODO_COLA=0
ARCHIVO=""

while [[ $# -gt 0 ]]; do
    case "$1" in
        --cola) MODO_COLA=1; shift ;;
        --sitio) SITIO="$2"; shift 2 ;;
        --estacion) ESTACION="$2"; shift 2 ;;
        --nota) NOTA="$2"; shift 2 ;;
        --grabado-en) GRABADO_EN="$2"; shift 2 ;;
        --duracion) DURACION="$2"; shift 2 ;;
        --mantener) MANTENER=1; shift ;;
        -h|--help) sed -n '2,20p' "$0"; exit 0 ;;
        -*) echo "Parametro desconocido: $1" >&2; exit 1 ;;
        *) ARCHIVO="$1"; shift ;;
    esac
done

registrar() { logger -t jocotoco-enviar -- "$*" 2>/dev/null || true; echo "$*"; }

verificar_entorno() {
    for archivo in "$CERT" "$KEY" "$CA"; do
        [[ -r "$archivo" ]] || { registrar "ERROR: no se puede leer $archivo"; exit 1; }
    done

    # Un certificado vencido produce un rechazo TLS sin mensaje claro:
    # conviene detectarlo antes de intentar el envio.
    if command -v step >/dev/null 2>&1; then
        if ! step certificate verify "$CERT" --roots "$CA" >/dev/null 2>&1; then
            registrar "ADVERTENCIA: el certificado no valida contra la raiz; intentando renovar"
            step ca renew --force "$CERT" "$KEY" >/dev/null 2>&1 || \
                registrar "ERROR: la renovacion fallo; ejecute bootstrap-dispositivo.sh de nuevo"
        fi
    fi
}

enviar() {
    local archivo="$1" intento=1 codigo cuerpo respuesta sha metadatos
    sha="$(sha256sum "$archivo" | cut -d' ' -f1)"

    metadatos="$(jq -nc \
        --arg sitio "$SITIO" --arg estacion "$ESTACION" --arg nota "$NOTA" --arg duracion "$DURACION" \
        '[{sitio:$sitio},{estacion:$estacion},{nota:$nota},{duracion_segundos:$duracion}]
         | map(with_entries(select(.value != ""))) | add // {}')"

    local -a cabeceras=(
        -H "X-Audio-SHA256: ${sha}"
        -H "X-Nombre-Archivo: $(basename "$archivo")"
        -H "X-Metadatos: ${metadatos}"
        -H "Expect:"
    )

    if [[ -n "$GRABADO_EN" ]]; then
        cabeceras+=(-H "X-Grabado-En: ${GRABADO_EN}")
    else
        # Fecha de modificacion del archivo como momento de grabacion.
        cabeceras+=(-H "X-Grabado-En: $(date -u -r "$archivo" +%Y-%m-%dT%H:%M:%SZ)")
    fi

    while (( intento <= REINTENTOS )); do
        respuesta="$(curl -sS --show-error \
            --cert "$CERT" --key "$KEY" --cacert "$CA" \
            --tlsv1.2 \
            --max-time 180 --connect-timeout 15 \
            -X POST "${API_URL}/v1/grabaciones" \
            -H "Content-Type: $(tipo_medio "$archivo")" \
            "${cabeceras[@]}" \
            --data-binary "@${archivo}" \
            -w '\n%{http_code}' 2>&1)" || respuesta=$'\n000'

        codigo="$(tail -n1 <<<"$respuesta")"
        cuerpo="$(sed '$d' <<<"$respuesta")"

        case "$codigo" in
            201|200)
                registrar "OK ($codigo) $(basename "$archivo"): $(jq -r '.grabacion.id // "sin id"' <<<"$cuerpo" 2>/dev/null)"
                return 0
                ;;
            400|413|415|422)
                registrar "RECHAZADO ($codigo) $(basename "$archivo"): $(jq -r '.detail // .' <<<"$cuerpo" 2>/dev/null)"
                return 1
                ;;
            401|403)
                registrar "NO AUTORIZADO ($codigo): $(jq -r '.detail // .' <<<"$cuerpo" 2>/dev/null)"
                registrar "Revise la vigencia del certificado: step ca renew --force $CERT $KEY"
                return 1
                ;;
            *)
                registrar "Intento ${intento}/${REINTENTOS} fallo (codigo ${codigo:-sin respuesta})"
                sleep $(( intento * 5 ))
                ((intento++))
                ;;
        esac
    done

    return 2
}

tipo_medio() {
    case "${1,,}" in
        *.wav) echo "audio/wav" ;;
        *.flac) echo "audio/flac" ;;
        *.opus) echo "audio/opus" ;;
        *.ogg) echo "audio/ogg" ;;
        *.mp3) echo "audio/mpeg" ;;
        *) echo "application/octet-stream" ;;
    esac
}

archivar() {
    local archivo="$1"
    if (( MANTENER )); then
        return
    fi

    if [[ -d "$ENVIADAS" ]]; then
        mv -f "$archivo" "$ENVIADAS/" && return
    fi

    rm -f "$archivo"
}

verificar_entorno

if (( MODO_COLA )); then
    shopt -s nullglob
    pendientes=("$COLA"/*.wav "$COLA"/*.flac "$COLA"/*.ogg "$COLA"/*.opus "$COLA"/*.mp3)
    if (( ${#pendientes[@]} == 0 )); then
        exit 0
    fi

    registrar "Procesando ${#pendientes[@]} grabacion(es) en cola"
    fallos=0
    for archivo in "${pendientes[@]}"; do
        # Se salta lo que aun se esta grabando.
        if [[ "$archivo" == *.parcial || -f "$archivo.parcial" ]]; then
            continue
        fi

        estado=0
        enviar "$archivo" || estado=$?

        if (( estado == 0 )); then
            archivar "$archivo"
        elif (( estado == 1 )); then
            mv -f "$archivo" "$archivo.rechazado"
            registrar "Movido a $(basename "$archivo").rechazado para revision manual"
        else
            ((fallos++))
        fi
    done

    exit $(( fallos > 0 ? 2 : 0 ))
fi

if [[ -z "$ARCHIVO" ]]; then
    echo "Indique el archivo de audio o use --cola." >&2
    exit 1
fi

[[ -f "$ARCHIVO" ]] || { echo "No existe el archivo: $ARCHIVO" >&2; exit 1; }

estado=0
enviar "$ARCHIVO" || estado=$?

if (( estado == 0 )); then
    archivar "$ARCHIVO"
    exit 0
fi

if (( estado == 2 )); then
    # Error temporal: se deja en la cola para el temporizador.
    if [[ -d "$COLA" && "$(dirname "$(readlink -f "$ARCHIVO")")" != "$(readlink -f "$COLA")" ]]; then
        cp -f "$ARCHIVO" "$COLA/" && registrar "Encolado para reintento: $(basename "$ARCHIVO")"
    fi
fi

exit $estado
