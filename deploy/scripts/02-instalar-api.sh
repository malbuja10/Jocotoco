#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# Instala el API de audio en Ubuntu 24.04: PHP 8.3, nginx, usuario de
# servicio, directorios de datos y base SQLite.
#
# Uso:  sudo ./02-instalar-api.sh [--origen /ruta/al/repo] \
#                                 [--destino /srv/jocotoco] \
#                                 [--dominio api.jocotoco.local]
# ---------------------------------------------------------------------------
set -euo pipefail

ORIGEN="$(cd "$(dirname "$0")/../.." && pwd)"
DESTINO="/srv/jocotoco"
DATOS="/var/lib/jocotoco"
LOGS="/var/log/jocotoco"
DOMINIO="api.jocotoco.local"
USUARIO="jocotoco"

while [[ $# -gt 0 ]]; do
    case "$1" in
        --origen) ORIGEN="$2"; shift 2 ;;
        --destino) DESTINO="$2"; shift 2 ;;
        --dominio) DOMINIO="$2"; shift 2 ;;
        *) echo "Parametro desconocido: $1" >&2; exit 1 ;;
    esac
done

[[ $EUID -eq 0 ]] || { echo "Ejecute este script con sudo." >&2; exit 1; }

echo "==> Instalando PHP 8.3, nginx y utilidades"
apt-get update -qq
apt-get install -y -qq \
    nginx \
    php8.3-fpm php8.3-cli php8.3-sqlite3 php8.3-mbstring php8.3-curl php8.3-xml \
    sqlite3 unzip curl rsync jq ca-certificates

php_version="$(php -r 'echo PHP_VERSION;')"
echo "==> PHP instalado: $php_version"
case "$php_version" in
    8.3.*) ;;
    *) echo "    ADVERTENCIA: se esperaba PHP 8.3.x (Ubuntu 24.04 lo trae por defecto)." ;;
esac

if ! command -v composer >/dev/null 2>&1; then
    echo "==> Instalando Composer"
    curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php
    php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer --quiet
    rm -f /tmp/composer-setup.php
fi

echo "==> Creando usuario de servicio ${USUARIO}"
id -u "$USUARIO" >/dev/null 2>&1 || useradd --system --home "$DATOS" --shell /usr/sbin/nologin "$USUARIO"

echo "==> Creando directorios"
install -d -o "$USUARIO" -g "$USUARIO" -m 0750 "$DATOS" "$DATOS/audio" "$DATOS/tmp"
install -d -o "$USUARIO" -g "$USUARIO" -m 0750 "$LOGS"
install -d -o www-data -g www-data -m 0700 "$DATOS/nginx-tmp"
install -d -o root -g root -m 0755 /etc/jocotoco
install -d -o root -g "$USUARIO" -m 0750 /etc/jocotoco/tls

echo "==> Copiando la aplicacion a ${DESTINO}"
install -d -m 0755 "$DESTINO"
rsync -a --delete \
    --exclude '.git' --exclude 'var' --exclude 'tests' --exclude '.phpunit.cache' \
    "$ORIGEN"/ "$DESTINO"/

echo "==> Instalando dependencias de produccion"
cd "$DESTINO"
COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader --no-interaction --quiet

chown -R root:"$USUARIO" "$DESTINO"
chmod -R o-rwx "$DESTINO"

echo "==> Aplicando migraciones"
runuser -u "$USUARIO" -- env JOCOTOCO_DATA_DIR="$DATOS" JOCOTOCO_LOG_PATH="$LOGS/api.log" \
    php "$DESTINO/bin/jocotoco" migrate

echo "==> Configurando php-fpm"
install -m 0644 "$ORIGEN/deploy/php/jocotoco-pool.conf" /etc/php/8.3/fpm/pool.d/jocotoco.conf
install -m 0644 "$ORIGEN/deploy/php/opcache-jocotoco.ini" /etc/php/8.3/fpm/conf.d/99-jocotoco.ini
sed -i "s|env\[JOCOTOCO_LOG_PATH\] = .*|env[JOCOTOCO_LOG_PATH] = $LOGS/api.log|" /etc/php/8.3/fpm/pool.d/jocotoco.conf

echo "==> Configurando nginx"
sed "s/api\.jocotoco\.local/$DOMINIO/g" "$ORIGEN/deploy/nginx/jocotoco-api.conf" \
    > /etc/nginx/sites-available/jocotoco-api
ln -sf /etc/nginx/sites-available/jocotoco-api /etc/nginx/sites-enabled/jocotoco-api
rm -f /etc/nginx/sites-enabled/default

echo "==> Instalando rotacion de logs y purga programada"
install -m 0644 "$ORIGEN/deploy/systemd/jocotoco-purga.service" /etc/systemd/system/
install -m 0644 "$ORIGEN/deploy/systemd/jocotoco-purga.timer" /etc/systemd/system/
cat > /etc/logrotate.d/jocotoco <<'ROTATE'
/var/log/jocotoco/*.log {
    daily
    rotate 30
    missingok
    notifempty
    compress
    delaycompress
    create 0640 jocotoco jocotoco
}
ROTATE

systemctl daemon-reload
systemctl restart php8.3-fpm

cat <<RESUMEN

===========================================================================
 API instalado en ${DESTINO}

 Datos      : ${DATOS}        (audio en ${DATOS}/audio)
 Logs       : ${LOGS}/api.log
 Dominio    : ${DOMINIO}

 Falta emitir el certificado TLS del servidor con step-ca:

   sudo ./03-emitir-cert-servidor.sh --dominio ${DOMINIO} \\
        --ca-url https://ca.jocotoco.local:8443 --huella <HUELLA_RAIZ>

 Despues:  sudo nginx -t && sudo systemctl reload nginx
===========================================================================
RESUMEN
