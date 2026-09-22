#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# Instala e inicializa step-ca en Ubuntu 24.04 (autoridad certificadora
# interna que emite los certificados del API y de las Raspberry Pi).
#
# Uso:  sudo ./01-instalar-step-ca.sh [--dominio api.jocotoco.local] \
#                                     [--ca-dns ca.jocotoco.local]
#
# Al terminar imprime la huella de la raiz: ese dato es el que necesita
# cada Raspberry para confiar en la CA (step ca bootstrap --fingerprint).
# ---------------------------------------------------------------------------
set -euo pipefail

VERSION_STEP="${VERSION_STEP:-0.28.2}"
VERSION_STEP_CA="${VERSION_STEP_CA:-0.28.1}"
CA_DNS="ca.jocotoco.local"
CA_NOMBRE="Jocotoco"
CA_PUERTO="8443"
STEPPATH="/etc/step"
USUARIO_CA="step"

while [[ $# -gt 0 ]]; do
    case "$1" in
        --ca-dns) CA_DNS="$2"; shift 2 ;;
        --nombre) CA_NOMBRE="$2"; shift 2 ;;
        --puerto) CA_PUERTO="$2"; shift 2 ;;
        *) echo "Parametro desconocido: $1" >&2; exit 1 ;;
    esac
done

if [[ $EUID -ne 0 ]]; then
    echo "Ejecute este script con sudo." >&2
    exit 1
fi

arquitectura="$(dpkg --print-architecture)"

echo "==> Instalando dependencias"
apt-get update -qq
apt-get install -y -qq curl ca-certificates jq

# step-cli se publica en el repositorio smallstep/cli.
if ! command -v step >/dev/null 2>&1; then
    paquete="$(mktemp --suffix=.deb)"
    curl -fsSL "https://github.com/smallstep/cli/releases/download/v${VERSION_STEP}/step-cli_${VERSION_STEP}_${arquitectura}.deb" -o "$paquete"
    dpkg -i "$paquete"
    rm -f "$paquete"
fi

if ! command -v step-ca >/dev/null 2>&1; then
    paquete="$(mktemp --suffix=.deb)"
    curl -fsSL "https://github.com/smallstep/certificates/releases/download/v${VERSION_STEP_CA}/step-ca_${VERSION_STEP_CA}_${arquitectura}.deb" -o "$paquete"
    dpkg -i "$paquete"
    rm -f "$paquete"
fi

echo "==> Creando usuario de servicio ${USUARIO_CA}"
id -u "$USUARIO_CA" >/dev/null 2>&1 || useradd --system --home "$STEPPATH" --shell /usr/sbin/nologin "$USUARIO_CA"
install -d -o "$USUARIO_CA" -g "$USUARIO_CA" -m 0700 "$STEPPATH"

if [[ -f "$STEPPATH/config/ca.json" ]]; then
    echo "==> step-ca ya estaba inicializada en $STEPPATH"
else
    echo "==> Inicializando la CA"
    clave_ca="$(mktemp)"
    chmod 600 "$clave_ca"
    openssl rand -base64 32 | tr -d '\n' > "$clave_ca"

    STEPPATH="$STEPPATH" runuser -u "$USUARIO_CA" -- step ca init \
        --deployment-type=standalone \
        --name="$CA_NOMBRE" \
        --dns="$CA_DNS" \
        --address=":$CA_PUERTO" \
        --provisioner="dispositivos" \
        --password-file="$clave_ca"

    install -o "$USUARIO_CA" -g "$USUARIO_CA" -m 0600 "$clave_ca" "$STEPPATH/password.txt"
    rm -f "$clave_ca"

    echo "==> Ampliando la vigencia maxima de los certificados de dispositivo a 24 h"
    STEPPATH="$STEPPATH" runuser -u "$USUARIO_CA" -- \
        step ca provisioner update dispositivos \
            --x509-default-dur=24h --x509-max-dur=24h --x509-min-dur=5m \
            --admin-subject=step --password-file="$STEPPATH/password.txt" || \
        echo "    (ajuste los claims manualmente en $STEPPATH/config/ca.json si el comando fallo)"
fi

echo "==> Instalando la unidad systemd step-ca"
install -m 0644 "$(dirname "$0")/../systemd/step-ca.service" /etc/systemd/system/step-ca.service
systemctl daemon-reload
systemctl enable --now step-ca

sleep 2
systemctl is-active --quiet step-ca && echo "==> step-ca activo en https://${CA_DNS}:${CA_PUERTO}"

huella="$(step certificate fingerprint "$STEPPATH/certs/root_ca.crt")"
cat <<RESUMEN

===========================================================================
 CA lista.

 URL de la CA     : https://${CA_DNS}:${CA_PUERTO}
 Raiz             : ${STEPPATH}/certs/root_ca.crt
 Huella de la raiz: ${huella}

 Guarde la huella: cada Raspberry la necesita para ejecutar

   step ca bootstrap --ca-url https://${CA_DNS}:${CA_PUERTO} \\
                     --fingerprint ${huella}

 Siguiente paso en el servidor del API:
   sudo ./02-instalar-api.sh
   sudo ./03-emitir-cert-servidor.sh --dominio api.jocotoco.local
===========================================================================
RESUMEN
