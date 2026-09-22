#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# Genera el token de un solo uso con el que una Raspberry Pi obtiene su
# primer certificado de step-ca, y opcionalmente la agrega a la lista
# blanca del API.
#
# Ejecutar en el servidor de la CA:
#   sudo ./04-registrar-dispositivo.sh rpi-yanacocha-01
#
# El token vence en 5 minutos: entreguelo al dispositivo por un canal
# seguro (ssh) y ejecute alli deploy/raspberry/bootstrap-dispositivo.sh
# ---------------------------------------------------------------------------
set -euo pipefail

DISPOSITIVO="${1:-}"
PROVISIONER="${PROVISIONER:-dispositivos}"
STEPPATH="${STEPPATH:-/etc/step}"
CA_DNS="${CA_DNS:-ca.jocotoco.local}"
CA_PUERTO="${CA_PUERTO:-8443}"

if [[ -z "$DISPOSITIVO" ]]; then
    cat >&2 <<USO
Uso: sudo ./04-registrar-dispositivo.sh <nombre-del-dispositivo>

El nombre queda como CN y SAN DNS del certificado, y es la identidad con
la que el API registra las grabaciones (ej. rpi-yanacocha-01).
USO
    exit 1
fi

if [[ ! "$DISPOSITIVO" =~ ^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$ ]]; then
    echo "El nombre debe ser un hostname valido en minusculas." >&2
    exit 1
fi

[[ -f "$STEPPATH/config/ca.json" ]] || { echo "No se encontro la CA en $STEPPATH." >&2; exit 1; }

export STEPPATH

token="$(runuser -u step -- env STEPPATH="$STEPPATH" step ca token "$DISPOSITIVO" \
    --provisioner "$PROVISIONER" \
    --password-file "$STEPPATH/password.txt" \
    --not-after 5m)"

huella="$(step certificate fingerprint "$STEPPATH/certs/root_ca.crt")"

cat <<RESUMEN

===========================================================================
 Dispositivo   : ${DISPOSITIVO}
 CA            : https://${CA_DNS}:${CA_PUERTO}
 Huella raiz   : ${huella}

 Ejecute en la Raspberry Pi (el token vence en 5 minutos):

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
