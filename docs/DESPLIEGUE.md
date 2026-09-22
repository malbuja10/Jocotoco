# Despliegue en Ubuntu 24.04

Todo lo que sigue asume Ubuntu 24.04 LTS (trae PHP 8.3 y nginx 1.24 en los
repositorios oficiales, sin necesidad de PPA).

## 0. Requisitos

| Componente | Version | Origen |
|------------|---------|--------|
| Ubuntu | 24.04 LTS | — |
| PHP | 8.3 (`php8.3-fpm`, `php8.3-sqlite3`, `php8.3-mbstring`, `php8.3-curl`, `php8.3-xml`) | repositorio Ubuntu |
| nginx | 1.24 | repositorio Ubuntu |
| step-ca / step-cli | 0.28.x | paquetes `.deb` de smallstep |
| Composer | 2.x | getcomposer.org |

Decida antes los nombres DNS y que resuelvan desde los dispositivos:

- `ca.jocotoco.local` → servidor de step-ca (puerto 8443)
- `api.jocotoco.local` → servidor del API (puerto 443)

La CA y el API pueden convivir en la misma maquina; en produccion conviene
separarlas para que un compromiso del API no exponga las llaves de la CA.

## 1. Autoridad certificadora (step-ca)

```bash
sudo deploy/scripts/01-instalar-step-ca.sh --ca-dns ca.jocotoco.local
```

El script instala `step-cli` y `step-ca`, crea el usuario de servicio `step`,
inicializa la CA en `/etc/step` con un provisioner JWK llamado `dispositivos`,
limita la vigencia de los certificados a 24 h e instala la unidad
`step-ca.service`.

Al final imprime la **huella de la raiz**. Guardela: es lo que cada Raspberry
usa para confiar en la CA.

```bash
# Comprobaciones
systemctl status step-ca
step certificate fingerprint /etc/step/certs/root_ca.crt
step ca health --ca-url https://ca.jocotoco.local:8443 \
     --root /etc/step/certs/root_ca.crt
```

La contrasena de las llaves queda en `/etc/step/password.txt` (modo 0600,
usuario `step`). Respaldela junto con `/etc/step/secrets/` fuera de la
maquina: sin ella no se pueden emitir mas certificados.

### Por que 24 horas

Un certificado corto hace innecesaria la revocacion: si un dispositivo se
pierde, basta con no renovarle. `step ca renew` corre cada 4 h en la Raspberry
y renueva cuando quedan menos de 8 h. Para cambiar la politica:

```bash
sudo -u step STEPPATH=/etc/step step ca provisioner update dispositivos \
     --x509-default-dur=168h --x509-max-dur=168h \
     --password-file /etc/step/password.txt
sudo systemctl reload step-ca
```

## 2. API

```bash
sudo deploy/scripts/02-instalar-api.sh --dominio api.jocotoco.local
```

Hace lo siguiente:

- instala PHP 8.3, nginx, SQLite y Composer;
- crea el usuario de servicio `jocotoco`;
- crea `/var/lib/jocotoco{,/audio,/tmp}` (0750), `/var/log/jocotoco` y
  `/var/lib/jocotoco/nginx-tmp` (para `client_body_temp_path`);
- copia el codigo a `/srv/jocotoco` e instala dependencias sin `--dev`;
- aplica las migraciones (`bin/jocotoco migrate`);
- instala el pool de php-fpm, el `.ini` de OPcache, el sitio de nginx,
  la rotacion de logs y el temporizador de purga.

### Ajustes que conviene revisar

`/etc/php/8.3/fpm/pool.d/jocotoco.conf`:

```ini
env[JOCOTOCO_CA_ISSUER_COMMON_NAME] = "Jocotoco Intermediate CA"
env[JOCOTOCO_ALLOWED_DEVICES] = "rpi-yanacocha-01,rpi-mindo-02"
env[JOCOTOCO_MAX_UPLOAD_BYTES] = 67108864
```

El CN exacto de su CA intermedia:

```bash
step certificate inspect /etc/step/certs/intermediate_ca.crt --short
```

Si cambia `JOCOTOCO_MAX_UPLOAD_BYTES`, ajuste tambien:

- nginx: `client_max_body_size`
- php-fpm: `upload_max_filesize` (≥ el limite) y `post_max_size` (algo mayor)

Tras cualquier cambio: `sudo systemctl reload php8.3-fpm nginx`.

## 3. Certificado TLS del servidor

```bash
sudo deploy/scripts/03-emitir-cert-servidor.sh \
     --dominio api.jocotoco.local \
     --ca-url https://ca.jocotoco.local:8443 \
     --huella <HUELLA_DE_LA_RAIZ>
```

Emite la hoja del servidor en `/etc/jocotoco/tls/`, publica la raiz en
`/etc/step/certs/root_ca.crt` (la que nginx usa para **validar clientes**) y
activa `jocotoco-cert-renew.timer`, que renueva cada 8 h y recarga nginx.

```bash
sudo nginx -t && sudo systemctl reload nginx
curl -s --cacert /etc/step/certs/root_ca.crt \
     https://api.jocotoco.local/v1/salud | jq
```

### Como queda el mTLS

En `deploy/nginx/jocotoco-api.conf`:

```nginx
ssl_client_certificate /etc/step/certs/root_ca.crt;   # raiz de step-ca
ssl_verify_client optional;                           # /v1/salud sin cert
ssl_verify_depth 2;                                   # raiz + intermedia
```

`optional` permite que el monitoreo consulte `/v1/salud`. El resto de las
rutas se bloquea en el `location /` (`if ($ssl_client_verify != SUCCESS)`) y
**la aplicacion lo vuelve a comprobar**: si alguien despliega el API sin
nginx delante, sigue exigiendo el certificado.

nginx entrega el certificado a PHP en `SSL_CLIENT_CERT` usando
`$ssl_client_escaped_cert` (URL-encoded, una sola linea). La aplicacion
tambien acepta el formato multilinea de `$ssl_client_cert`.

## 4. Registrar dispositivos

Uno por Raspberry, en el servidor de la CA:

```bash
sudo deploy/scripts/04-registrar-dispositivo.sh rpi-yanacocha-01
```

Imprime un token valido **5 minutos** y el comando exacto a ejecutar en el
dispositivo. Continue en [`RASPBERRY.md`](RASPBERRY.md).

## 5. Verificacion

```bash
# Sin certificado: 403 en nginx
curl -sk https://api.jocotoco.local/v1/grabaciones -o /dev/null -w '%{http_code}\n'

# Con certificado de dispositivo
curl -s --cert /etc/jocotoco/tls/dispositivo.crt \
        --key  /etc/jocotoco/tls/dispositivo.key \
        --cacert /etc/jocotoco/tls/raiz-ca.crt \
        https://api.jocotoco.local/v1/yo | jq

# Como interpreta el API un certificado concreto
php /srv/jocotoco/bin/jocotoco inspeccionar-cert /etc/jocotoco/tls/dispositivo.crt
```

## Operacion

```bash
# Ultimas grabaciones
sudo -u jocotoco php /srv/jocotoco/bin/jocotoco listar
sudo -u jocotoco php /srv/jocotoco/bin/jocotoco listar rpi-yanacocha-01

# Configuracion efectiva (utilisimo cuando algo no toma efecto)
sudo -u jocotoco php /srv/jocotoco/bin/jocotoco config

# Log de la aplicacion (JSON por linea)
sudo tail -f /var/log/jocotoco/api.log | jq -c '[.ts,.nivel,.mensaje,.contexto]'

# Purga manual (el timer diario ejecuta "purgar 365")
sudo -u jocotoco php /srv/jocotoco/bin/jocotoco purgar 90
```

### Respaldos

Tres cosas, en este orden de importancia:

1. `/etc/step/` (llaves de la CA y contrasena) — sin esto no se emiten
   certificados nuevos;
2. `/var/lib/jocotoco/audio/` — las grabaciones;
3. `/var/lib/jocotoco/jocotoco.sqlite` — metadatos. Copielo en caliente con
   `sqlite3 ... ".backup"`, no con `cp`:

```bash
sudo -u jocotoco sqlite3 /var/lib/jocotoco/jocotoco.sqlite \
     ".backup '/var/backups/jocotoco-$(date +%F).sqlite'"
```

### Actualizar el codigo

```bash
sudo deploy/scripts/02-instalar-api.sh --origen /ruta/al/repo   # rsync + composer + migrate
sudo systemctl reload php8.3-fpm                                # OPcache no valida timestamps
```

## Problemas frecuentes

| Sintoma | Causa habitual |
|---------|----------------|
| `401` con `SSL_CLIENT_VERIFY=NONE` | El cliente no envio certificado, o `ssl_verify_client` no esta activo |
| `403` "no fue emitido por la CA esperada" | `JOCOTOCO_CA_ISSUER_COMMON_NAME` no coincide con el CN de la intermedia (compruebe con `step certificate inspect`) |
| `403` "el certificado expiro" | El timer de renovacion del dispositivo no corre, o su reloj esta desfasado (`timedatectl`) |
| `403` "aun no es valido" | Reloj del servidor atrasado; la tolerancia es de 60 s (`JOCOTOCO_CERTIFICATE_CLOCK_SKEW_SECONDS`) |
| `413` de nginx (HTML, no JSON) | `client_max_body_size` menor que el audio |
| `500` al subir | Permisos de `/var/lib/jocotoco/{audio,tmp}`: deben pertenecer a `jocotoco` |
| El cambio de una variable no toma efecto | Falta `systemctl reload php8.3-fpm`; verifique con `bin/jocotoco config` |
| `open_basedir` en los logs | Se movio el `data_dir` fuera de la lista del pool de php-fpm |
