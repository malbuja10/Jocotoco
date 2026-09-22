#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# Emite (y programa la renovacion de) el certificado TLS del servidor del
# API usando step-ca. Tambien copia la raiz de la CA donde nginx la espera
# para validar los certificados de las Raspberry.
#
# Uso: sudo ./03-emitir-cert-servidor.sh \
#          --dominio api.jocotoco.local \
#          --ca-url https://ca.jocotoco.local:8443 \
#          --huella <HUELLA_DE_LA_RAIZ>
#
# Si step-ca corre en esta misma maquina puede omitir --ca-url/--huella:
# se toman de /etc/step.
# ---------------------------------------------------------------------------
set -euo pipefail

DOMINIO="api.jocotoco.local"
CA_URL=""
HUELLA=""
DESTINO_TLS="/etc/jocotoco/tls"
RAIZ_NGINX="/etc/step/certs/root_ca.crt"
VIGENCIA="24h"

while [[ $# -gt 0 ]]; do
    case "$1" in
        --dominio) DOMINIO="$2"; shift 2 ;;
        --ca-url) CA_URL="$2"; shift 2 ;;
        --huella) HUELLA="$2"; shift 2 ;;
        --vigencia) VIGENCIA="$2"; shift 2 ;;
        *) echo "Parametro desconocido: $1" >&2; exit 1 ;;
    esac
done

[[ $EUID -eq 0 ]] || { echo "Ejecute este script con sudo." >&2; exit 1; }
command -v step >/dev/null 2>&1 || { echo "Falta step-cli. Ejecute 01-instalar-step-ca.sh o instale step-cli." >&2; exit 1; }

export STEPPATH="${STEPPATH:-/root/.step}"

if [[ -n "$CA_URL" && -n "$HUELLA" ]]; then
    echo "==> Confiando en la CA remota ${CA_URL}"
    step ca bootstrap --ca-url "$CA_URL" --fingerprint "$HUELLA" --force
    RAIZ_NGINX="$STEPPATH/certs/root_ca.crt"
elif [[ -f /etc/step/certs/root_ca.crt ]]; then
    echo "==> Usando la CA local de /etc/step"
    export STEPPATH=/etc/step
else
    echo "Indique --ca-url y --huella, o instale step-ca en esta maquina." >&2
    exit 1
fi

install -d -o root -g jocotoco -m 0750 "$DESTINO_TLS"

echo "==> Solicitando el certificado del servidor para ${DOMINIO}"
# El token se pide de forma interactiva contra el provisioner de la CA.
step ca certificate "$DOMINIO" \
    "$DESTINO_TLS/servidor.crt" "$DESTINO_TLS/servidor.key" \
    --san "$DOMINIO" \
    --not-after "$VIGENCIA" \
    --force

chown root:jocotoco "$DESTINO_TLS/servidor.crt" "$DESTINO_TLS/servidor.key"
chmod 0640 "$DESTINO_TLS/servidor.crt"
chmod 0640 "$DESTINO_TLS/servidor.key"

echo "==> Publicando la raiz de la CA para la verificacion de clientes"
install -d -m 0755 /etc/step/certs
install -m 0644 "$RAIZ_NGINX" /etc/step/certs/root_ca.crt

echo "==> Instalando la renovacion automatica (systemd timer)"
install -m 0644 "$(dirname "$0")/../systemd/jocotoco-cert-renew.service" /etc/systemd/system/
install -m 0644 "$(dirname "$0")/../systemd/jocotoco-cert-renew.timer" /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now jocotoco-cert-renew.timer

nginx -t && systemctl reload nginx

cat <<RESUMEN

===========================================================================
 Certificado del servidor emitido.

   Hoja  : ${DESTINO_TLS}/servidor.crt
   Llave : ${DESTINO_TLS}/servidor.key
   Raiz  : /etc/step/certs/root_ca.crt  (nginx valida clientes con esta)

 Renovacion: jocotoco-cert-renew.timer (cada 8 h, recarga nginx).
 Verifique:  curl -sk https://${DOMINIO}/v1/salud | jq
===========================================================================
RESUMEN
