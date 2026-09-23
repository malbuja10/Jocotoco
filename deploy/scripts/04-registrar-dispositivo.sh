#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# Genera el token de un solo uso con el que una Raspberry Pi obtiene su
# primer certificado de step-ca, y opcionalmente la agrega a la lista
# blanca del API.
#
# Ejecutar en el servidor de la CA:
#   sudo ./04-registrar-dispositivo.sh rpi-yanacocha-01
#   sudo ./04-registrar-dispositivo.sh rpi-yanacocha-01 --validez 60m
#
# El token es de un solo uso y por defecto vence en 60 minutos: tiempo de
# sobra para llegar al equipo por ssh, sin dejarlo valido indefinidamente.
# Entreguelo por un canal seguro y ejecute en la Raspberry
# deploy/raspberry/bootstrap-dispositivo.sh
# ---------------------------------------------------------------------------
set -euo pipefail

DISPOSITIVO=""
VALIDEZ="60m"
PROVISIONER="${PROVISIONER:-dispositivos}"
STEPPATH="${STEPPATH:-/etc/step}"
CA_DNS="${CA_DNS:-}"
CA_PUERTO="${CA_PUERTO:-}"

while [[ $# -gt 0 ]]; do
    case "$1" in
        --validez) VALIDEZ="$2"; shift 2 ;;
        --provisioner) PROVISIONER="$2"; shift 2 ;;
        -*) echo "Parametro desconocido: $1" >&2; exit 1 ;;
        *) DISPOSITIVO="$1"; shift ;;
    esac
done

if [[ -z "$DISPOSITIVO" ]]; then
    cat >&2 <<USO
Uso: sudo ./04-registrar-dispositivo.sh <nombre-del-dispositivo> [--validez 60m]

El nombre queda como CN y SAN DNS del certificado, y es la identidad con
la que el API registra las grabaciones (ej. rpi-yanacocha-01).

--validez controla cuanto dura el TOKEN de alta (no el certificado):
60m por defecto. La vigencia del certificado la fija la CA; para equipos
sin conexion prolongada vea 07-vigencia-dispositivos.sh.
USO
    exit 1
fi

if [[ ! "$DISPOSITIVO" =~ ^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$ ]]; then
    echo "El nombre debe ser un hostname valido en minusculas." >&2
    exit 1
fi

[[ -f "$STEPPATH/config/ca.json" ]] || { echo "No se encontro la CA en $STEPPATH." >&2; exit 1; }

export STEPPATH

# El nombre y el puerto de la CA se leen de su propia configuracion, para
# que el comando que se imprime sea el que el dispositivo puede usar.
if [[ -z "$CA_DNS" || -z "$CA_PUERTO" ]] && command -v jq >/dev/null 2>&1; then
    CA_DNS="${CA_DNS:-$(jq -r '.dnsNames | last' "$STEPPATH/config/ca.json")}"
    CA_PUERTO="${CA_PUERTO:-$(jq -r '.address' "$STEPPATH/config/ca.json" | sed 's/^.*://')}"
fi
CA_DNS="${CA_DNS:-ca.jocotoco.local}"
CA_PUERTO="${CA_PUERTO:-8443}"

token="$(runuser -u step -- env STEPPATH="$STEPPATH" step ca token "$DISPOSITIVO" \
    --provisioner "$PROVISIONER" \
    --password-file "$STEPPATH/password.txt" \
    --not-after "$VALIDEZ")"

huella="$(step certificate fingerprint "$STEPPATH/certs/root_ca.crt")"

cat <<RESUMEN

===========================================================================
 Dispositivo   : ${DISPOSITIVO}
 CA            : https://${CA_DNS}:${CA_PUERTO}
 Huella raiz   : ${huella}

 Vigencia del certificado que emitira la CA: $(jq -r --arg p "$PROVISIONER" '.authority.provisioners[] | select(.name==$p) | .claims.defaultTLSCertDuration // "24h (por defecto)"' "$STEPPATH/config/ca.json" 2>/dev/null || echo 'ver ca.json')

 Ejecute en la Raspberry Pi (el token vence en ${VALIDEZ}):

   sudo ./bootstrap-dispositivo.sh \\
        --nombre ${DISPOSITIVO} \\
        --ca-url https://${CA_DNS}:${CA_PUERTO} \\
        --huella ${huella} \\
        --token '${token}'

 Si el API usa lista blanca, agregue el dispositivo en
 /etc/php/8.3/fpm/pool.d/jocotoco.conf (JOCOTOCO_ALLOWED_DEVICES)
 y recargue php-fpm.
===========================================================================
RESUMEN
