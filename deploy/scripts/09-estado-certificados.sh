#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# Estado de los certificados, en un solo comando: que dice la politica, que
# esta emitiendo la CA de verdad, como esta el certificado del servidor y
# que falta por corregir.
#
# Existe porque la vigencia depende de tres cosas que no se ven juntas: los
# claims del provisioner, lo que pidio quien emitio el certificado y el
# umbral de renovacion de cada equipo. Cuando no coinciden, lo normal es
# enterarse semanas despues.
#
# Uso:  ./09-estado-certificados.sh
#       ./09-estado-certificados.sh --provisioner dispositivos
#
# Devuelve 0 si todo cuadra con la politica y 1 si hay algo que corregir,
# de modo que sirve en un cron.
# ---------------------------------------------------------------------------
set -uo pipefail

AQUI="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=lib-politica.sh
source "$AQUI/lib-politica.sh"

PROVISIONER="dispositivos"
STEPPATH="${STEPPATH:-/etc/step}"
TLS_DIR="${TLS_DIR:-/etc/jocotoco/tls}"
RENOVACION_ENV="${RENOVACION_ENV:-/etc/jocotoco/cert-renew.env}"
BASE_API="${BASE_API:-/var/lib/jocotoco/jocotoco.sqlite}"

while [[ $# -gt 0 ]]; do
    case "$1" in
        --provisioner) PROVISIONER="$2"; shift 2 ;;
        --steppath) STEPPATH="$2"; shift 2 ;;
        --tls-dir) TLS_DIR="$2"; shift 2 ;;
        --politica) POLITICA_ARCHIVO="$2"; shift 2 ;;
        -h|--help) sed -n '3,16p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) echo "Parametro desconocido: $1" >&2; exit 1 ;;
    esac
done

CONFIG="$STEPPATH/config/ca.json"
problemas=0
declare -a arreglos=()

bien() { printf '  \033[32m%s\033[0m  %s\n' 'OK' "$1"; }
mal()  { printf '  \033[31m%s\033[0m  %s\n' '!!' "$1"; problemas=$(( problemas + 1 )); }
dato() { printf '      %s\n' "$1"; }

politica_leer
umbral_politica="$(politica_umbral_horas "$POLITICA_VIGENCIA_DIAS" "$POLITICA_RENOVAR_CADA_DIAS")"

echo
echo "Politica  (${POLITICA_ORIGEN})"
dato "Vigencia de los certificados : ${POLITICA_VIGENCIA_DIAS} dias"
dato "Se renuevan cada             : ${POLITICA_RENOVAR_CADA_DIAS} dias (umbral ${umbral_politica} h)"
dato "Renovables tras caducar      : ${POLITICA_RENOVAR_TRAS_CADUCAR}"

echo
echo "Autoridad certificadora  (${CONFIG})"
if [[ ! -r "$CONFIG" ]]; then
    mal "No puedo leer la configuracion de la CA."
    dato "Si la CA esta en otra maquina, ejecute este script alli."
elif ! command -v jq >/dev/null 2>&1; then
    mal "Falta jq, que hace falta para leer ca.json."
    arreglos+=("sudo apt-get install -y jq")
else
    horas_ca="$(claims_horas "$CONFIG" "$PROVISIONER")"
    horas_max="$(claims_horas "$CONFIG" "$PROVISIONER" maxTLSCertDuration)"
    caducar_ca="$(claims_renovar_tras_caducar "$CONFIG" "$PROVISIONER")"
    dias_ca=$(( horas_ca / 24 ))

    if ! jq -e --arg p "$PROVISIONER" '.authority.provisioners[] | select(.name == $p)' "$CONFIG" >/dev/null 2>&1; then
        mal "El provisioner \"${PROVISIONER}\" no existe en la CA."
        dato "Hay: $(jq -r '.authority.provisioners[].name' "$CONFIG" | tr '\n' ' ')"
    elif (( dias_ca < POLITICA_VIGENCIA_DIAS )); then
        mal "La CA emite ${dias_ca} dia(s) ($horas_ca h), no los ${POLITICA_VIGENCIA_DIAS} de la politica."
        dato "Esto es lo que hace que un equipo reciba un certificado de un dia"
        dato "aunque la politica diga ${POLITICA_VIGENCIA_DIAS}: manda la CA, no quien lo pide."
        arreglos+=("sudo ./07-vigencia-dispositivos.sh --dias ${POLITICA_VIGENCIA_DIAS} --renovar-cada ${POLITICA_RENOVAR_CADA_DIAS}")
    else
        bien "Emite ${dias_ca} dias por defecto (maximo $(( horas_max / 24 )))."
    fi

    if [[ "$POLITICA_RENOVAR_TRAS_CADUCAR" == true && "$caducar_ca" != true ]]; then
        mal "La CA no permite renovar un certificado ya caducado."
        dato "Un equipo que vuelva del campo con el certificado vencido"
        dato "necesitaria un token nuevo en lugar de renovar solo."
        arreglos+=("sudo ./07-vigencia-dispositivos.sh --dias ${POLITICA_VIGENCIA_DIAS} --renovar-cada ${POLITICA_RENOVAR_CADA_DIAS}")
    elif [[ "$caducar_ca" == true ]]; then
        bien "Permite renovar tras caducar."
    fi

    if systemctl is-active --quiet step-ca 2>/dev/null; then
        bien "El servicio step-ca esta activo."
    else
        mal "El servicio step-ca no esta activo: nadie podra renovar."
        arreglos+=("sudo systemctl status step-ca")
    fi
fi

echo
echo "Certificado del servidor  (${TLS_DIR}/servidor.crt)"
if [[ ! -r "$TLS_DIR/servidor.crt" ]]; then
    mal "No existe todavia."
    arreglos+=("sudo ./03-emitir-cert-servidor.sh --dominio <su-dominio>")
else
    dias_srv="$(cert_dias "$TLS_DIR/servidor.crt")"
    restan="$(cert_dias_restantes "$TLS_DIR/servidor.crt")"
    nombre="$(openssl x509 -in "$TLS_DIR/servidor.crt" -noout -subject | sed 's/.*CN *= *//')"
    dato "Nombre: ${nombre}"

    if (( restan < 0 )); then
        mal "Caduco hace $(( -restan )) dia(s). El API no acepta conexiones."
        arreglos+=("sudo ./03-emitir-cert-servidor.sh --dominio ${nombre}")
    elif (( dias_srv < POLITICA_VIGENCIA_DIAS )); then
        mal "Vigencia de ${dias_srv} dia(s), no los ${POLITICA_VIGENCIA_DIAS} de la politica; le quedan ${restan}."
        arreglos+=("sudo ./03-emitir-cert-servidor.sh --dominio ${nombre}")
    else
        bien "Vigencia de ${dias_srv} dias; le quedan ${restan}."
    fi

    umbral_actual="$(sed -n 's/^JOCOTOCO_RENOVAR_ANTES=\(.*\)$/\1/p' "$RENOVACION_ENV" 2>/dev/null | tail -1)"
    if [[ -z "$umbral_actual" ]]; then
        mal "Sin umbral de renovacion: ${RENOVACION_ENV} no existe, se usaran 8 h."
        arreglos+=("sudo ./03-emitir-cert-servidor.sh --dominio ${nombre}")
    elif [[ "$umbral_actual" != "${umbral_politica}h" ]]; then
        mal "El umbral de renovacion es ${umbral_actual}, la politica pide ${umbral_politica}h."
        arreglos+=("sudo ./03-emitir-cert-servidor.sh --dominio ${nombre}")
    else
        bien "Se renovara cada ${POLITICA_RENOVAR_CADA_DIAS} dias (umbral ${umbral_actual})."
    fi

    if systemctl is-enabled --quiet jocotoco-cert-renew.timer 2>/dev/null; then
        bien "El temporizador de renovacion esta habilitado."
    else
        mal "El temporizador jocotoco-cert-renew.timer no esta habilitado."
        arreglos+=("sudo systemctl enable --now jocotoco-cert-renew.timer")
    fi
fi

echo
echo "Dispositivos"
if [[ -r "$BASE_API" ]] && command -v php >/dev/null 2>&1; then
    filas="$(php -r '
        $p = new PDO("sqlite:file:" . $argv[1] . "?mode=ro");
        foreach ($p->query("SELECT id, last_seen_at FROM devices ORDER BY last_seen_at DESC LIMIT 20") as $f) {
            printf("      %-22s ultima conexion %s\n", $f["id"], $f["last_seen_at"]);
        }' "$BASE_API" 2>/dev/null)"
    if [[ -n "$filas" ]]; then
        echo "$filas"
        dato ""
        dato "La vigencia de cada equipo se consulta desde el propio equipo:"
        dato "  step certificate inspect /etc/jocotoco/tls/dispositivo.crt --short"
        dato "  grep RENOVAR /etc/jocotoco/dispositivo.env"
    else
        dato "Ningun dispositivo se ha conectado todavia."
    fi
else
    dato "(no se pudo leer ${BASE_API}; ejecutelo en el servidor del API)"
fi

echo
if (( problemas == 0 )); then
    printf ' \033[32m%s\033[0m\n' "Todo cuadra con la politica."
    echo
    exit 0
fi

printf ' \033[31m%d problema(s).\033[0m Para corregirlos, desde %s:\n' "$problemas" "$AQUI"
printf '%s\n' "${arreglos[@]}" | awk '!visto[$0]++ {print "   " $0}'
echo
echo " Los equipos ya desplegados toman la vigencia nueva en su siguiente"
echo " renovacion; para adelantarla, en cada Raspberry:"
echo "   sudo systemctl start jocotoco-renovar-cert.service"
echo
exit 1
