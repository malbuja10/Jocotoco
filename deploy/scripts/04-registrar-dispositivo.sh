#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# Genera el token de un solo uso con el que una Raspberry Pi obtiene su
# primer certificado de step-ca, y opcionalmente la agrega a la lista
# blanca del API.
#
# Ejecutar en el servidor de la CA:
#   sudo ./04-registrar-dispositivo.sh rpi-yanacocha-01
#   sudo ./04-registrar-dispositivo.sh rpi-yanacocha-01 --validez 72h
#
# El token es de un solo uso y por defecto vence en 24 horas: un equipo que
# sale a campo puede provisionarse al llegar, sin que el token caduque en el
# viaje. Suba o baje con --validez (ej. 72h para una salida de tres dias).
# Entreguelo por un canal seguro y ejecute en la Raspberry
# deploy/raspberry/bootstrap-dispositivo.sh
# ---------------------------------------------------------------------------
set -euo pipefail

AQUI="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=lib-politica.sh
source "$AQUI/lib-politica.sh"
politica_leer

DISPOSITIVO=""
VALIDEZ="24h"
PERMITIR_CORTA=false
PROVISIONER="${PROVISIONER:-dispositivos}"
STEPPATH="${STEPPATH:-/etc/step}"
CA_DNS="${CA_DNS:-}"
CA_PUERTO="${CA_PUERTO:-}"

while [[ $# -gt 0 ]]; do
    case "$1" in
        --validez) VALIDEZ="$2"; shift 2 ;;
        --provisioner) PROVISIONER="$2"; shift 2 ;;
        --permitir-vigencia-corta) PERMITIR_CORTA=true; shift ;;
        -*) echo "Parametro desconocido: $1" >&2; exit 1 ;;
        *) DISPOSITIVO="$1"; shift ;;
    esac
done

if [[ -z "$DISPOSITIVO" ]]; then
    cat >&2 <<USO
Uso: sudo ./04-registrar-dispositivo.sh <nombre-del-dispositivo> [--validez 24h]

El nombre queda como CN y SAN DNS del certificado, y es la identidad con
la que el API registra las grabaciones (ej. rpi-yanacocha-01).

--validez controla cuanto dura el TOKEN de alta (no el certificado):
24h por defecto, suficiente para provisionar el equipo al llegar a campo.
La vigencia del certificado la fija la CA (90 dias); vea
07-vigencia-dispositivos.sh.
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

horas_ca=0
# Antes de dar el token: comprobar que la CA va a emitir lo que dice la
# politica. Es el momento en que se nota, porque el equipo se lleva el
# certificado al campo y alli nadie ve su vigencia.
if command -v jq >/dev/null 2>&1; then
    horas_ca="$(claims_horas "$STEPPATH/config/ca.json" "$PROVISIONER")"
    dias_ca=$(( horas_ca / 24 ))

    if (( dias_ca < POLITICA_VIGENCIA_DIAS )) && [[ "$PERMITIR_CORTA" != true ]]; then
        cat >&2 <<AVISO
ERROR: la CA emitiria un certificado de ${dias_ca} dia(s) (${horas_ca} h), no los
       ${POLITICA_VIGENCIA_DIAS} dias de la politica (${POLITICA_ORIGEN}).

       El dispositivo quedaria sin servicio al dia siguiente y habria que
       volver a darlo de alta. No es cosa de lo que se pida al emitir: la
       vigencia la decide el provisioner de la CA.

       Corrijalo y vuelva a intentarlo:
         sudo ./07-vigencia-dispositivos.sh --dias ${POLITICA_VIGENCIA_DIAS} --renovar-cada ${POLITICA_RENOVAR_CADA_DIAS}
         ./09-estado-certificados.sh

       Si de verdad quiere un certificado corto (una prueba, por ejemplo),
       repita con --permitir-vigencia-corta.
AVISO
        exit 1
    fi
fi

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

 Vigencia que emitira la CA: $( (( horas_ca > 0 )) && echo "$(( horas_ca / 24 )) dias (${horas_ca} h)" || echo "ver ca.json (falta jq)")
 Se renovara solo cada ${POLITICA_RENOVAR_CADA_DIAS} dias

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
