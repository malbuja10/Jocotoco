#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# Emite (y programa la renovacion de) el certificado TLS del servidor del
# API usando step-ca. Tambien copia la raiz de la CA donde Apache la espera
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
RAIZ_CA="/etc/step/certs/root_ca.crt"
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

# El certificado y su renovacion automatica usan siempre este STEPPATH,
# tambien cuando la CA corre en esta misma maquina: jocotoco-cert-renew.service
# lo necesita inicializado para poder ejecutar "step ca renew".
export STEPPATH="${STEPPATH:-/root/.step}"

if [[ -z "$CA_URL" || -z "$HUELLA" ]]; then
    if [[ -f /etc/step/config/ca.json ]]; then
        command -v jq >/dev/null 2>&1 || { echo "Falta jq: sudo apt-get install -y jq" >&2; exit 1; }
        echo "==> Tomando los datos de la CA local de /etc/step"
        # "address" en ca.json ya viene como ":8443".
        CA_URL="${CA_URL:-https://$(jq -r '.dnsNames[0]' /etc/step/config/ca.json)$(jq -r '.address' /etc/step/config/ca.json)}"
        HUELLA="${HUELLA:-$(step certificate fingerprint /etc/step/certs/root_ca.crt)}"
    else
        echo "Indique --ca-url y --huella, o instale step-ca en esta maquina." >&2
        exit 1
    fi
fi

# El nombre de la CA tiene que resolver desde aqui: step valida el
# certificado TLS de la CA contra ese nombre, asi que no sirve apuntar a
# 127.0.0.1. Si la CA corre en esta misma maquina, basta una linea en
# /etc/hosts.
ca_host="${CA_URL#*://}"
ca_host="${ca_host%%:*}"
if ! getent hosts "$ca_host" >/dev/null 2>&1; then
    echo "ERROR: el nombre ${ca_host} no resuelve en este servidor." >&2
    echo "       step valida el certificado de la CA contra ese nombre." >&2
    echo "       Si la CA corre aqui mismo, agreguelo a /etc/hosts:" >&2
    echo "         echo '127.0.0.1 ${ca_host}' >> /etc/hosts" >&2
    echo "       Si corre en otra maquina, publique el nombre en su DNS." >&2
    exit 1
fi

echo "==> Confiando en la CA ${CA_URL}"
step ca bootstrap --ca-url "$CA_URL" --fingerprint "$HUELLA" --force
RAIZ_CA="$STEPPATH/certs/root_ca.crt"

if ! step ca health --ca-url "$CA_URL" --root "$RAIZ_CA" >/dev/null 2>&1; then
    echo "ERROR: la CA no responde en ${CA_URL}." >&2
    echo "       Revise:  systemctl status step-ca" >&2
    echo "                ss -ltnp | grep 8443" >&2
    exit 1
fi

install -d -o root -g jocotoco -m 0750 "$DESTINO_TLS"

# El provisioner JWK pide la contrasena de su llave para emitir el token.
# Si la CA es local, es la misma que quedo en /etc/step/password.txt y se
# pasa por archivo para no tener que teclearla; si no, step la pregunta.
CLAVE_PROVISIONER=()
if [[ -r /etc/step/password.txt ]]; then
    CLAVE_PROVISIONER=(--provisioner-password-file /etc/step/password.txt)
    echo "    (usando la contrasena del provisioner de /etc/step/password.txt)"
fi

echo "==> Solicitando el certificado del servidor para ${DOMINIO}"
step ca certificate "$DOMINIO" \
    "$DESTINO_TLS/servidor.crt" "$DESTINO_TLS/servidor.key" \
    --san "$DOMINIO" \
    --not-after "$VIGENCIA" \
    "${CLAVE_PROVISIONER[@]}" \
    --force

chown root:jocotoco "$DESTINO_TLS/servidor.crt" "$DESTINO_TLS/servidor.key"
chmod 0640 "$DESTINO_TLS/servidor.crt"
chmod 0640 "$DESTINO_TLS/servidor.key"

echo "==> Publicando la raiz de la CA para la verificacion de clientes"
install -d -m 0755 /etc/step/certs
install -m 0644 "$RAIZ_CA" /etc/step/certs/root_ca.crt

echo "==> Instalando la renovacion automatica (systemd timer)"
install -m 0644 "$(dirname "$0")/../systemd/jocotoco-cert-renew.service" /etc/systemd/system/
install -m 0644 "$(dirname "$0")/../systemd/jocotoco-cert-renew.timer" /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now jocotoco-cert-renew.timer

echo "==> Habilitando el sitio del API"
if [[ ! -f /etc/apache2/sites-available/jocotoco-api.conf ]]; then
    echo "ERROR: falta /etc/apache2/sites-available/jocotoco-api.conf; ejecute antes 02-instalar-api.sh." >&2
    exit 1
fi

# Si otro proceso ya escucha en 443, Apache no podra arrancar y el error de
# systemd no lo dice con claridad.
if command -v ss >/dev/null 2>&1; then
    ocupa_443="$(ss -ltnpH 2>/dev/null | awk '$4 ~ /:443$/ {print $6}' | head -1 || true)"
    if [[ -n "$ocupa_443" && "$ocupa_443" != *apache2* ]]; then
        echo "    ADVERTENCIA: el puerto 443 ya lo escucha otro proceso:"
        echo "                 ${ocupa_443}"
        echo "                 Apache no podra arrancar hasta liberarlo."
    fi
fi

a2ensite -q jocotoco-api
# El sitio por defecto se aparta solo si sigue habilitado; los demas vhosts
# del servidor no se tocan.
a2dissite -q 000-default default-ssl 2>/dev/null || true

apache2ctl configtest

# "systemctl reload" falla si el servicio no estaba corriendo: 02 configura
# Apache pero no lo arranca, porque hasta ahora faltaban los certificados.
if systemctl is-active --quiet apache2; then
    accion=reload
else
    accion=start
fi

echo "==> Apache: ${accion}"
if ! systemctl "$accion" apache2 || ! systemctl is-active --quiet apache2; then
    echo "ERROR: Apache no pudo ${accion}. La sintaxis es valida, asi que el" >&2
    echo "       problema es de arranque. Revise, en este orden:" >&2
    echo "         journalctl -xeu apache2.service --no-pager | tail -30" >&2
    echo "         systemctl status apache2.service --no-pager | tail -20" >&2
    echo "         ss -ltnp | grep -E ':(80|443)'    # puerto ocupado" >&2
    echo "         apache2ctl -S                     # vhosts y conflictos de ServerName" >&2
    echo "       El sitio queda habilitado; corrija y ejecute: systemctl start apache2" >&2
    exit 1
fi

cat <<RESUMEN

===========================================================================
 Certificado del servidor emitido.

   Hoja  : ${DESTINO_TLS}/servidor.crt
   Llave : ${DESTINO_TLS}/servidor.key
   Raiz  : /etc/step/certs/root_ca.crt  (Apache valida clientes con esta)

 Renovacion: jocotoco-cert-renew.timer (cada 8 h, recarga Apache).
 Verifique:  curl -sk https://${DOMINIO}/v1/salud | jq
===========================================================================
RESUMEN
