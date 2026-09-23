#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# Instala el API de audio en Ubuntu 24.04: PHP 8.3, Apache, usuario de
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
PUERTO="443"
USUARIO="jocotoco"

while [[ $# -gt 0 ]]; do
    case "$1" in
        --origen) ORIGEN="$2"; shift 2 ;;
        --destino) DESTINO="$2"; shift 2 ;;
        --dominio) DOMINIO="$2"; shift 2 ;;
        --puerto) PUERTO="$2"; shift 2 ;;
        *) echo "Parametro desconocido: $1" >&2; exit 1 ;;
    esac
done

[[ $EUID -eq 0 ]] || { echo "Ejecute este script con sudo." >&2; exit 1; }

if [[ -r /etc/os-release ]]; then
    . /etc/os-release
    if [[ "${VERSION_ID:-}" != "24.04" ]]; then
        echo "    ADVERTENCIA: este script se probo en Ubuntu 24.04; aqui corre ${PRETTY_NAME:-desconocido}."
    fi
fi

echo "==> Comprobando la disponibilidad de los paquetes"
apt-get update -qq

faltan=()
for paquete in apache2 php8.3-fpm php8.3-cli php8.3-sqlite3 php8.3-mbstring; do
    politica="$(apt-cache policy "$paquete" 2>/dev/null || true)"
    if [[ -z "$politica" ]] || grep -q 'Candidate: (none)' <<<"$politica"; then
        faltan+=("$paquete")
    fi
done

if (( ${#faltan[@]} > 0 )); then
    echo "ERROR: estos paquetes no existen en los repositorios de este sistema:" >&2
    printf '         %s\n' "${faltan[@]}" >&2
    echo "       Ubuntu 24.04 los trae en los repositorios oficiales. En 22.04 o" >&2
    echo "       anterior, PHP 8.3 requiere el PPA de Ondrej Sury:" >&2
    echo "         add-apt-repository -y ppa:ondrej/php && apt-get update" >&2
    echo "       Revise tambien: apt-cache policy apache2 php8.3-fpm" >&2
    exit 1
fi

echo "==> Instalando PHP 8.3, Apache y utilidades"
apt-get install -y -qq \
    apache2 \
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

# El arbol de codigo pertenece a root y es de solo lectura para todos: lo
# leen dos usuarios distintos (php-fpm como jocotoco y apache como
# www-data), asi que quitarle el permiso de "otros" impediria que el
# servidor web pudiera atravesar el DocumentRoot. Aqui no va ningun secreto:
# las credenciales viven en /etc/jocotoco y en el entorno del pool.
chown -R root:root "$DESTINO"
find "$DESTINO" -type d -exec chmod 0755 {} +
find "$DESTINO" -type f -exec chmod 0644 {} +
chmod 0755 "$DESTINO/bin/jocotoco"
find "$DESTINO/deploy" -name '*.sh' -exec chmod 0755 {} +

echo "==> Aplicando migraciones"
runuser -u "$USUARIO" -- env JOCOTOCO_DATA_DIR="$DATOS" JOCOTOCO_LOG_PATH="$LOGS/api.log" \
    php "$DESTINO/bin/jocotoco" migrate

echo "==> Configurando php-fpm"
install -m 0644 "$ORIGEN/deploy/php/jocotoco-pool.conf" /etc/php/8.3/fpm/pool.d/jocotoco.conf
install -m 0644 "$ORIGEN/deploy/php/opcache-jocotoco.ini" /etc/php/8.3/fpm/conf.d/99-jocotoco.ini
sed -i "s|env\[JOCOTOCO_LOG_PATH\] = .*|env[JOCOTOCO_LOG_PATH] = $LOGS/api.log|" /etc/php/8.3/fpm/pool.d/jocotoco.conf

# Un sitio ya habilitado con el mismo ServerName y el mismo puerto gana
# sobre el nuestro (Apache usa el primero que coincide), de modo que el API
# quedaria como configuracion muerta. Mejor detenerse aqui.
choque="$(grep -rlE "^[[:space:]]*ServerName[[:space:]]+${DOMINIO}([[:space:]]|\$)" \
    /etc/apache2/sites-enabled/ 2>/dev/null | grep -v 'jocotoco-api' || true)"

if [[ -n "$choque" ]] && [[ "$PUERTO" == "443" ]]; then
    echo "ERROR: este Apache ya sirve ${DOMINIO} en otro sitio habilitado:" >&2
    printf '         %s\n' $choque >&2
    echo "       Dos VirtualHost con el mismo ServerName y puerto no conviven:" >&2
    echo "       Apache atiende con el primero y el API quedaria inerte." >&2
    echo "       Elija una de estas dos salidas:" >&2
    echo "         a) un nombre propio para el API (recomendado):" >&2
    echo "              --dominio api.\${DOMINIO#*.}" >&2
    echo "         b) un puerto propio, sin tocar el sitio existente:" >&2
    echo "              --puerto 8443" >&2
    exit 1
fi

echo "==> Configurando Apache (${DOMINIO}:${PUERTO})"
# MPM event + php-fpm por mod_proxy_fcgi: mod_php obligaria a usar prefork.
a2dismod -q -f mpm_prefork 2>/dev/null || true
a2enmod -q mpm_event ssl proxy_fcgi headers alias reqtimeout rewrite
sed "s/api\.jocotoco\.local/$DOMINIO/g" "$ORIGEN/deploy/apache/jocotoco-api.conf" \
    > /etc/apache2/sites-available/jocotoco-api.conf

if [[ "$PUERTO" != "443" ]]; then
    # Puerto propio: se ajusta el VirtualHost, se agrega su Listen y se
    # elimina la redireccion del puerto 80, que pertenece al otro sitio.
    sed -i -e "s/^<VirtualHost \*:443>/<VirtualHost *:${PUERTO}>/" \
           -e "/=== INICIO REDIRECCION HTTP ===/,/=== FIN REDIRECCION HTTP ===/d" \
           /etc/apache2/sites-available/jocotoco-api.conf
    echo "Listen ${PUERTO}" > /etc/apache2/conf-available/jocotoco-puerto.conf
    a2enconf -q jocotoco-puerto
else
    rm -f /etc/apache2/conf-enabled/jocotoco-puerto.conf
fi
install -m 0644 "$ORIGEN/deploy/apache/jocotoco-endurecimiento.conf" /etc/apache2/conf-available/
a2enconf -q jocotoco-endurecimiento
grep -q 'ServerName' /etc/apache2/conf-available/jocotoco-endurecimiento.conf || \
    echo "ServerName $DOMINIO" >> /etc/apache2/conf-available/jocotoco-endurecimiento.conf

# El sitio NO se habilita aqui: su VirtualHost referencia el certificado del
# servidor y la raiz de la CA, y Apache se niega a arrancar si esos archivos
# no existen ("SSLCertificateFile: file ... does not exist or is empty").
# Lo habilita 03-emitir-cert-servidor.sh, que es quien los crea.
otros_sitios="$(ls -1 /etc/apache2/sites-enabled/ 2>/dev/null | grep -v '^jocotoco-api' || true)"
if [[ -n "$otros_sitios" ]]; then
    echo "    NOTA: este Apache ya tiene otros sitios habilitados:"
    printf '          %s\n' $otros_sitios
    echo "          03-emitir-cert-servidor.sh no los toca; revise que no haya"
    echo "          conflicto de ServerName o de puertos con ${DOMINIO}."
fi

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

if [[ -s /etc/jocotoco/tls/servidor.crt && -s /etc/step/certs/root_ca.crt ]]; then
    apache2ctl configtest
    systemctl reload apache2
else
    echo "==> Apache queda configurado pero el sitio aun no esta habilitado:"
    echo "    faltan el certificado del servidor y la raiz de la CA."
fi

cat <<RESUMEN

===========================================================================
 API instalado en ${DESTINO}

 Datos      : ${DATOS}        (audio en ${DATOS}/audio)
 Logs       : ${LOGS}/api.log
 Dominio    : ${DOMINIO}

 El sitio de Apache esta en sites-available pero TODAVIA NO habilitado:
 lo habilita el paso siguiente, que emite el certificado TLS con step-ca:

   sudo ./03-emitir-cert-servidor.sh --dominio ${DOMINIO} \\
        --ca-url https://ca.jocotoco.local:8443 --huella <HUELLA_RAIZ>

 Despues:  sudo apache2ctl configtest && sudo systemctl reload apache2
===========================================================================
RESUMEN
