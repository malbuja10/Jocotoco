#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# Instala el panel web de monitoreo sobre un API ya instalado (02 y 03).
#
# Uso:
#   sudo ./09-instalar-panel.sh --dominio dev.minkafab.com [--puerto 8445] \
#        [--usuario admin] [--cert /ruta/fullchain.pem --key /ruta/privkey.pem]
#
# Sin --cert/--key se usa el certificado de Let's Encrypt del dominio si
# existe (/etc/letsencrypt/live/<dominio>/), y si no, el del API emitido por
# step-ca (el navegador lo marcara como no confiable salvo que se instale la
# raiz de la CA en los equipos de los operadores).
#
# Crea:
#   - usuario del sistema jocotoco_panel (en el grupo jocotoco, para leer audio)
#   - rol de PostgreSQL jocotoco_panel: lectura de todo, escritura solo en
#     device_profiles y event_reviews
#   - pool de php-fpm y sitio de Apache con autenticacion basica
# Se puede ejecutar de nuevo para actualizar: conserva usuarios y contrasenas.
# ---------------------------------------------------------------------------
set -euo pipefail

DESTINO="/srv/jocotoco"
DOMINIO="api.jocotoco.local"
PUERTO="8445"
USUARIO_WEB="admin"
CERT=""
KEY=""
SERVICIO="jocotoco"
PANEL="jocotoco_panel"

while [[ $# -gt 0 ]]; do
    case "$1" in
        --destino) DESTINO="$2"; shift 2 ;;
        --dominio) DOMINIO="$2"; shift 2 ;;
        --puerto) PUERTO="$2"; shift 2 ;;
        --usuario) USUARIO_WEB="$2"; shift 2 ;;
        --cert) CERT="$2"; shift 2 ;;
        --key) KEY="$2"; shift 2 ;;
        *) echo "Parametro desconocido: $1" >&2; exit 1 ;;
    esac
done

[[ $EUID -eq 0 ]] || { echo "Ejecute este script con sudo." >&2; exit 1; }
[[ -f "$DESTINO/panel/public/index.php" ]] || {
    echo "ERROR: no se encontro el panel en $DESTINO/panel. Ejecute antes 02-instalar-api.sh." >&2; exit 1; }
id -u "$SERVICIO" >/dev/null 2>&1 || { echo "ERROR: falta el usuario $SERVICIO (instale el API primero)." >&2; exit 1; }

if ss -ltnH "( sport = :$PUERTO )" 2>/dev/null | grep -q . && ! ss -ltnpH "( sport = :$PUERTO )" | grep -q apache2; then
    echo "ERROR: el puerto $PUERTO lo usa otro proceso. Elija otro con --puerto." >&2
    exit 1
fi

if [[ -z "$CERT" ]]; then
    if [[ -s "/etc/letsencrypt/live/$DOMINIO/fullchain.pem" ]]; then
        CERT="/etc/letsencrypt/live/$DOMINIO/fullchain.pem"
        KEY="/etc/letsencrypt/live/$DOMINIO/privkey.pem"
    else
        CERT="/etc/jocotoco/tls/servidor.crt"
        KEY="/etc/jocotoco/tls/servidor.key"
        echo "    AVISO: sin certificado de Let's Encrypt para $DOMINIO; se usa el de step-ca."
    fi
fi
[[ -s "$CERT" && -s "$KEY" ]] || { echo "ERROR: no existe el certificado $CERT o la llave $KEY." >&2; exit 1; }

echo "==> Paquetes"
apt-get install -y -qq apache2-utils php8.3-pgsql >/dev/null

echo "==> Usuario del sistema $PANEL"
id -u "$PANEL" >/dev/null 2>&1 || useradd --system --home /var/lib/jocotoco-panel --shell /usr/sbin/nologin "$PANEL"
usermod -a -G "$SERVICIO" "$PANEL"
install -d -o "$PANEL" -g "$PANEL" -m 0700 /var/lib/jocotoco-panel /var/lib/jocotoco-panel/sesiones

echo "==> Rol de PostgreSQL $PANEL (lectura + revisiones)"
runuser -u postgres -- psql -q -d "$SERVICIO" -v ON_ERROR_STOP=1 <<SQL
DO \$\$ BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = '$PANEL') THEN
        CREATE ROLE $PANEL LOGIN;
    END IF;
END \$\$;
GRANT CONNECT ON DATABASE $SERVICIO TO $PANEL;
GRANT USAGE ON SCHEMA public TO $PANEL;
GRANT SELECT ON ALL TABLES IN SCHEMA public TO $PANEL;
GRANT INSERT, UPDATE, DELETE ON device_profiles, event_reviews TO $PANEL;
-- Las tablas que creen migraciones futuras tambien seran legibles.
ALTER DEFAULT PRIVILEGES FOR ROLE $SERVICIO IN SCHEMA public GRANT SELECT ON TABLES TO $PANEL;
SQL

echo "==> php-fpm"
install -m 0644 "$DESTINO/deploy/php/jocotoco-panel-pool.conf" /etc/php/8.3/fpm/pool.d/jocotoco-panel.conf
systemctl restart php8.3-fpm

echo "==> Usuarios del panel (/etc/jocotoco/panel.htpasswd)"
touch /etc/jocotoco/panel.htpasswd
chown root:www-data /etc/jocotoco/panel.htpasswd
chmod 0640 /etc/jocotoco/panel.htpasswd
if ! grep -q "^${USUARIO_WEB}:" /etc/jocotoco/panel.htpasswd; then
    echo "    Defina la contrasena de '$USUARIO_WEB':"
    htpasswd -B /etc/jocotoco/panel.htpasswd "$USUARIO_WEB"
fi

echo "==> Apache (puerto $PUERTO)"
a2enmod -q ssl proxy_fcgi headers auth_basic authn_file
sed -e "s|__PUERTO__|$PUERTO|g" -e "s|__DOMINIO__|$DOMINIO|g" -e "s|__CERT__|$CERT|g" -e "s|__KEY__|$KEY|g" \
    "$DESTINO/deploy/apache/jocotoco-panel.conf" > /etc/apache2/sites-available/jocotoco-panel.conf
a2ensite -q jocotoco-panel
if ! apache2ctl configtest; then
    a2dissite -q jocotoco-panel
    echo "ERROR: la configuracion no es valida; el sitio del panel se deshabilito." >&2
    exit 1
fi
systemctl reload apache2
command -v ufw >/dev/null && ufw status | grep -q active && ufw allow "$PUERTO/tcp" >/dev/null && echo "    ufw: abierto $PUERTO/tcp"

cat <<RESUMEN

===========================================================================
 Panel instalado:  https://${DOMINIO}:${PUERTO}/
 Usuario inicial:  ${USUARIO_WEB}
 Mas usuarios:     sudo htpasswd -B /etc/jocotoco/panel.htpasswd <usuario>
 Certificado:      ${CERT}
===========================================================================
RESUMEN
