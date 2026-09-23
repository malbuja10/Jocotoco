#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# Amplia la vigencia de los certificados de dispositivo, para equipos que
# pasan semanas sin conexion (por ejemplo una Raspberry en campo).
#
# Uso:  sudo ./07-vigencia-dispositivos.sh --dias 30
#       sudo ./07-vigencia-dispositivos.sh --dias 30 --permitir-renovar-expirados
#
# Por que: step-ca solo renueva un certificado que TODAVIA sea valido. Con
# vigencia de 24 h, un equipo sin internet mas de un dia queda fuera y hay
# que darlo de alta otra vez con un token nuevo.
#
# --permitir-renovar-expirados activa allowRenewalAfterExpiry: el equipo
# puede renovar aunque su certificado ya haya caducado. Comodo en campo,
# pero significa que quien tenga una copia del certificado y su llave puede
# seguir renovando indefinidamente. Si lo usa, mantenga la lista blanca del
# API (JOCOTOCO_ALLOWED_DEVICES) como interruptor real de baja.
#
# El script respalda ca.json, aplica los cambios, reinicia step-ca, EMITE UN
# CERTIFICADO DE PRUEBA para comprobar que la vigencia es la pedida, y
# revierte si algo falla.
# ---------------------------------------------------------------------------
set -euo pipefail

DIAS=30
DIAS_MAX=""
PROVISIONER="dispositivos"
PERMITIR_EXPIRADOS=false
STEPPATH="${STEPPATH:-/etc/step}"
CONFIG="$STEPPATH/config/ca.json"

while [[ $# -gt 0 ]]; do
    case "$1" in
        --dias) DIAS="$2"; shift 2 ;;
        --dias-maximo) DIAS_MAX="$2"; shift 2 ;;
        --provisioner) PROVISIONER="$2"; shift 2 ;;
        --permitir-renovar-expirados) PERMITIR_EXPIRADOS=true; shift ;;
        *) echo "Parametro desconocido: $1" >&2; exit 1 ;;
    esac
done

[[ $EUID -eq 0 ]] || { echo "Ejecute este script con sudo." >&2; exit 1; }
[[ -f "$CONFIG" ]] || { echo "No encuentro $CONFIG." >&2; exit 1; }
command -v jq >/dev/null 2>&1 || { echo "Falta jq: apt-get install -y jq" >&2; exit 1; }
[[ "$DIAS" =~ ^[0-9]+$ ]] && (( DIAS >= 1 )) || { echo "--dias debe ser un entero >= 1." >&2; exit 1; }

# El maximo, por defecto, triplica la vigencia pedida: deja margen para
# pedir certificados mas largos sin volver a tocar la CA.
[[ -n "$DIAS_MAX" ]] || DIAS_MAX=$(( DIAS * 3 ))
horas=$(( DIAS * 24 ))
horas_max=$(( DIAS_MAX * 24 ))

if ! jq -e --arg p "$PROVISIONER" '.authority.provisioners | map(select(.name == $p)) | length > 0' "$CONFIG" >/dev/null; then
    echo "ERROR: no existe el provisioner \"${PROVISIONER}\" en ${CONFIG}." >&2
    echo "       Provisioners disponibles: $(jq -r '.authority.provisioners[].name' "$CONFIG" | tr '\n' ' ')" >&2
    exit 1
fi

echo "==> Vigencia actual del provisioner ${PROVISIONER}:"
jq -r --arg p "$PROVISIONER" '.authority.provisioners[] | select(.name == $p) | .claims // "sin claims (24h por defecto)"' "$CONFIG"

marca="$(date +%Y%m%d-%H%M%S)"
respaldo="${CONFIG}.${marca}.bak"
cp -a "$CONFIG" "$respaldo"
echo "==> Respaldo: ${respaldo}"

claims="$(jq -nc --arg def "${horas}h" --arg max "${horas_max}h" --argjson exp "$PERMITIR_EXPIRADOS" \
    '{minTLSCertDuration:"5m", defaultTLSCertDuration:$def, maxTLSCertDuration:$max, allowRenewalAfterExpiry:$exp}')"

tmp="$(mktemp)"
jq --arg p "$PROVISIONER" --argjson c "$claims" \
    '.authority.provisioners |= map(if .name == $p then .claims = ((.claims // {}) * $c) else . end)' \
    "$CONFIG" > "$tmp"

if ! jq -e '.address and .dnsNames and (.authority.provisioners | length > 0)' "$tmp" >/dev/null; then
    rm -f "$tmp"
    echo "ERROR: el ca.json resultante no es valido; no se aplico nada." >&2
    exit 1
fi

install -o step -g step -m 0600 "$tmp" "$CONFIG"
rm -f "$tmp"
echo "==> Vigencia nueva: ${DIAS} dias por defecto, hasta ${DIAS_MAX} dias"
(( $(echo "$PERMITIR_EXPIRADOS" | grep -c true) )) && echo "    Renovacion tras caducidad: PERMITIDA"

restaurar() {
    echo "==> Restaurando ${respaldo}" >&2
    install -o step -g step -m 0600 "$respaldo" "$CONFIG"
    systemctl restart step-ca || true
    systemctl is-active --quiet step-ca \
        && echo "    step-ca restaurado con la configuracion anterior." >&2 \
        || echo "    ATENCION: step-ca sigue caido; revise journalctl -xeu step-ca" >&2
}

echo "==> Reiniciando step-ca"
systemctl restart step-ca
sleep 3

if ! systemctl is-active --quiet step-ca; then
    echo "ERROR: step-ca no arranco con los claims nuevos." >&2
    restaurar
    exit 1
fi

# --- Comprobacion real: emitir un certificado de prueba y medir su vigencia
puerto="$(jq -r '.address' "$CONFIG" | sed 's/^.*://')"
raiz="$STEPPATH/certs/root_ca.crt"
ca_dns="$(jq -r '.dnsNames[0]' "$CONFIG")"
export STEPPATH="${STEPPATH}"

if [[ -r "$STEPPATH/password.txt" ]]; then
    echo "==> Emitiendo un certificado de prueba para medir la vigencia"
    tmpdir="$(mktemp -d)"
    if step ca certificate "prueba-vigencia.invalid" "$tmpdir/p.crt" "$tmpdir/p.key" \
            --ca-url "https://${ca_dns}:${puerto}" --root "$raiz" \
            --provisioner "$PROVISIONER" \
            --provisioner-password-file "$STEPPATH/password.txt" \
            --force >/dev/null 2>&1; then
        inicio="$(date -d "$(openssl x509 -in "$tmpdir/p.crt" -noout -startdate | cut -d= -f2)" +%s)"
        fin="$(date -d "$(openssl x509 -in "$tmpdir/p.crt" -noout -enddate | cut -d= -f2)" +%s)"
        dias_reales=$(( (fin - inicio) / 86400 ))
        echo "    Vigencia obtenida: ${dias_reales} dias (pedidos ${DIAS})"

        if (( dias_reales < DIAS - 1 )); then
            echo "ERROR: la CA sigue emitiendo certificados de ${dias_reales} dias." >&2
            echo "       Los claims no surtieron efecto; se revierte el cambio." >&2
            rm -rf "$tmpdir"
            restaurar
            exit 1
        fi
    else
        echo "    ADVERTENCIA: no se pudo emitir el certificado de prueba."
        echo "                 Compruebe a mano con: step ca certificate ..."
    fi
    rm -rf "$tmpdir"
else
    echo "    (sin ${STEPPATH}/password.txt no se puede emitir la prueba automatica)"
fi

cat <<RESUMEN

===========================================================================
 Certificados de dispositivo: ${DIAS} dias (maximo ${DIAS_MAX})

 Los dispositivos YA registrados no cambian hasta su proxima renovacion.
 Para que tomen la vigencia nueva de inmediato, en cada Raspberry:

   sudo STEPPATH=/etc/jocotoco/step step ca renew --force \\
        /etc/jocotoco/tls/dispositivo.crt /etc/jocotoco/tls/dispositivo.key

 Los nuevos se registran igual que antes:
   sudo ./04-registrar-dispositivo.sh rpi-yanacocha-01 --validez 60m

 Respaldo de la configuracion previa: ${respaldo}
===========================================================================
RESUMEN
