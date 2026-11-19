#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# Politica de certificados: un unico sitio donde viven los dos numeros que
# antes estaban repartidos por cuatro scripts.
#
#   VIGENCIA_DIAS      cuanto dura un certificado (lo fija la CA)
#   RENOVAR_CADA_DIAS  cada cuanto se cambia (umbral de "step ca renew")
#
# 07-vigencia-dispositivos.sh los escribe; 03, 04 y 09 los leen. Si el
# archivo no existe todavia, valen los de aqui.
# ---------------------------------------------------------------------------

POLITICA_ARCHIVO="${POLITICA_ARCHIVO:-/etc/jocotoco/politica-certificados.env}"
POLITICA_VIGENCIA_DIAS=90
POLITICA_RENOVAR_CADA_DIAS=30
POLITICA_RENOVAR_TRAS_CADUCAR=true
POLITICA_ORIGEN="valores por defecto"

politica_leer() {
    [[ -r "$POLITICA_ARCHIVO" ]] || return 0

    local vigencia renovar caducar
    vigencia="$(sed -n 's/^VIGENCIA_DIAS=\([0-9]\{1,\}\)$/\1/p' "$POLITICA_ARCHIVO" | tail -1)"
    renovar="$(sed -n 's/^RENOVAR_CADA_DIAS=\([0-9]\{1,\}\)$/\1/p' "$POLITICA_ARCHIVO" | tail -1)"
    caducar="$(sed -n 's/^RENOVAR_TRAS_CADUCAR=\(true\|false\)$/\1/p' "$POLITICA_ARCHIVO" | tail -1)"

    [[ -n "$vigencia" ]] && POLITICA_VIGENCIA_DIAS="$vigencia"
    [[ -n "$renovar" ]] && POLITICA_RENOVAR_CADA_DIAS="$renovar"
    [[ -n "$caducar" ]] && POLITICA_RENOVAR_TRAS_CADUCAR="$caducar"
    POLITICA_ORIGEN="$POLITICA_ARCHIVO"
}

politica_escribir() {
    local vigencia="$1" renovar="$2" caducar="$3"

    install -d -m 0755 "$(dirname "$POLITICA_ARCHIVO")"
    cat > "$POLITICA_ARCHIVO" <<ENV
# Politica de certificados del proyecto Jocotoco.
# La escribe 07-vigencia-dispositivos.sh; la leen 03, 04 y 09.
VIGENCIA_DIAS=${vigencia}
RENOVAR_CADA_DIAS=${renovar}
RENOVAR_TRAS_CADUCAR=${caducar}
ENV
    chmod 0644 "$POLITICA_ARCHIVO"
}

# Duracion por defecto que la CA concede a un provisioner, en horas.
# Los claims usan duraciones de Go ("2160h", "24h", "90m"); sin claims, el
# valor de fabrica de step-ca son 24 h.
claims_horas() {
    local config="$1" provisioner="$2" clave="${3:-defaultTLSCertDuration}"
    local bruto

    bruto="$(jq -r --arg p "$provisioner" --arg c "$clave" \
        '.authority.provisioners[] | select(.name == $p) | .claims[$c] // empty' \
        "$config" 2>/dev/null)"

    [[ -n "$bruto" ]] || { echo 24; return 0; }

    local total=0 numero unidad resto="$bruto"
    while [[ "$resto" =~ ^([0-9]+)(h|m|s)(.*)$ ]]; do
        numero="${BASH_REMATCH[1]}"
        unidad="${BASH_REMATCH[2]}"
        resto="${BASH_REMATCH[3]}"
        case "$unidad" in
            h) total=$(( total + numero )) ;;
            m) total=$(( total + numero / 60 )) ;;
            s) total=$(( total + numero / 3600 )) ;;
        esac
    done

    echo "$total"
}

claims_renovar_tras_caducar() {
    local config="$1" provisioner="$2"
    jq -r --arg p "$provisioner" \
        '.authority.provisioners[] | select(.name == $p) | .claims.allowRenewalAfterExpiry // false' \
        "$config" 2>/dev/null || echo false
}

# Vigencia real de un certificado emitido, en dias.
cert_dias() {
    local cert="$1" inicio fin
    inicio="$(date -d "$(openssl x509 -in "$cert" -noout -startdate | cut -d= -f2)" +%s)" || return 1
    fin="$(date -d "$(openssl x509 -in "$cert" -noout -enddate | cut -d= -f2)" +%s)" || return 1
    echo $(( (fin - inicio) / 86400 ))
}

# Dias que le quedan a un certificado (negativo si ya caduco).
cert_dias_restantes() {
    local cert="$1" fin
    fin="$(date -d "$(openssl x509 -in "$cert" -noout -enddate | cut -d= -f2)" +%s)" || return 1
    echo $(( (fin - $(date +%s)) / 86400 ))
}

# El umbral de --expires-in que corresponde a la politica, en horas.
politica_umbral_horas() {
    local vigencia_dias="$1" renovar_dias="$2" horas
    horas=$(( (vigencia_dias - renovar_dias) * 24 ))
    (( horas < 8 )) && horas=$(( vigencia_dias * 24 / 3 ))
    (( horas < 8 )) && horas=8
    echo "$horas"
}
