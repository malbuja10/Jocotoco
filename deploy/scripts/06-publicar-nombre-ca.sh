#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# Agrega un nombre DNS a la CA para que los dispositivos puedan alcanzarla.
#
# step-ca solo atiende peticiones cuyo nombre figure en "dnsNames" de
# ca.json, y step-cli valida su certificado TLS contra ese nombre. Si la CA
# se inicializo con un nombre interno (ca.jocotoco.local), las Raspberry que
# esten fuera de esa red no pueden renovar su certificado.
#
# Uso:  sudo ./06-publicar-nombre-ca.sh dev.minkafab.com
#
# No reemplaza el nombre existente: lo agrega. Respalda ca.json y revierte
# si step-ca no vuelve a responder.
# ---------------------------------------------------------------------------
set -euo pipefail

NOMBRE="${1:-}"
STEPPATH="${STEPPATH:-/etc/step}"
CONFIG="$STEPPATH/config/ca.json"

if [[ -z "$NOMBRE" ]]; then
    echo "Uso: sudo ./06-publicar-nombre-ca.sh <nombre-dns-de-la-ca>" >&2
    echo "Ej.: sudo ./06-publicar-nombre-ca.sh dev.minkafab.com" >&2
    exit 1
fi

[[ $EUID -eq 0 ]] || { echo "Ejecute este script con sudo." >&2; exit 1; }
[[ -f "$CONFIG" ]] || { echo "No encuentro $CONFIG." >&2; exit 1; }
command -v jq >/dev/null 2>&1 || { echo "Falta jq: apt-get install -y jq" >&2; exit 1; }

if ! getent hosts "$NOMBRE" >/dev/null 2>&1; then
    echo "ADVERTENCIA: ${NOMBRE} no resuelve en este servidor."
    echo "             Los dispositivos si deben poder resolverlo."
fi

actuales="$(jq -r '.dnsNames | join(", ")' "$CONFIG")"
echo "==> Nombres actuales de la CA: ${actuales}"

if jq -e --arg n "$NOMBRE" '.dnsNames | index($n)' "$CONFIG" >/dev/null; then
    echo "==> ${NOMBRE} ya estaba declarado; no hay nada que hacer."
    exit 0
fi

marca="$(date +%Y%m%d-%H%M%S)"
respaldo="${CONFIG}.${marca}.bak"
cp -a "$CONFIG" "$respaldo"
echo "==> Respaldo: ${respaldo}"

tmp="$(mktemp)"
jq --arg n "$NOMBRE" '.dnsNames += [$n]' "$CONFIG" > "$tmp"

# Comprobar que el resultado sigue siendo un ca.json valido antes de aplicarlo.
if ! jq -e '.dnsNames and .address and .authority' "$tmp" >/dev/null; then
    rm -f "$tmp"
    echo "ERROR: el ca.json resultante no es valido; no se aplico nada." >&2
    exit 1
fi

install -o step -g step -m 0600 "$tmp" "$CONFIG"
rm -f "$tmp"
echo "==> Nombres nuevos: $(jq -r '.dnsNames | join(", ")' "$CONFIG")"

echo "==> Reiniciando step-ca (regenera su certificado TLS con el nombre nuevo)"
systemctl restart step-ca
sleep 3

puerto="$(jq -r '.address' "$CONFIG" | sed 's/^.*://')"
raiz="$STEPPATH/certs/root_ca.crt"

if ! systemctl is-active --quiet step-ca; then
    echo "ERROR: step-ca no arranco con el cambio. Se restaura el respaldo." >&2
    install -o step -g step -m 0600 "$respaldo" "$CONFIG"
    systemctl restart step-ca || true
    systemctl is-active --quiet step-ca \
        && echo "    step-ca restaurado con la configuracion anterior." >&2 \
        || echo "    ATENCION: step-ca sigue caido; revise journalctl -xeu step-ca" >&2
    exit 1
fi

if step ca health --ca-url "https://127.0.0.1:${puerto}" --root "$raiz" >/dev/null 2>&1 \
   || step ca health --ca-url "https://${NOMBRE}:${puerto}" --root "$raiz" >/dev/null 2>&1; then
    echo "==> step-ca responde correctamente."
else
    echo "    ADVERTENCIA: step-ca esta activo pero no respondio al chequeo de salud."
    echo "                 Revise: journalctl -xeu step-ca --no-pager | tail -20"
fi

huella="$(step certificate fingerprint "$raiz")"
cat <<RESUMEN

===========================================================================
 La CA ahora atiende tambien en: https://${NOMBRE}:${puerto}

 Para cada Raspberry, en el servidor de la CA:
   sudo ./04-registrar-dispositivo.sh rpi-yanacocha-01

 Y en la Raspberry, con el token que imprime:
   sudo ./bootstrap-dispositivo.sh \\
        --nombre rpi-yanacocha-01 \\
        --ca-url https://${NOMBRE}:${puerto} \\
        --huella ${huella} \\
        --token '<TOKEN>' \\
        --api-url https://<dominio-del-api>:<puerto-del-api>

 Compruebe que el puerto ${puerto} sea alcanzable desde los dispositivos
 (cortafuegos, NAT). Respaldo de la configuracion previa:
   ${respaldo}
===========================================================================
RESUMEN
