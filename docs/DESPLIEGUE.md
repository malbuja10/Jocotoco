# Despliegue en Ubuntu 24.04

Todo lo que sigue asume Ubuntu 24.04 LTS (trae PHP 8.3.6 y Apache 2.4.58 en
los repositorios oficiales, sin necesidad de PPA).

## 0. Requisitos

| Componente | Version | Origen |
|------------|---------|--------|
| Ubuntu | 24.04 LTS | — |
| PHP | 8.3 (`php8.3-fpm`, `php8.3-sqlite3`, `php8.3-mbstring`, `php8.3-curl`, `php8.3-xml`) | repositorio Ubuntu |
| Apache | 2.4.58 (`apache2`, con `ssl`, `proxy_fcgi`, `headers`, `alias`, `reqtimeout`, `rewrite`) | repositorio Ubuntu |
| step-ca / step-cli | 0.30.x (`step-ca` 0.30.2, `step-cli` 0.30.6 al momento de escribir) | paquetes `.deb` de smallstep |
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

El script resuelve la ultima version publicada de cada paquete con la API de
GitHub (y cae a una version fijada si no hay respuesta; puede forzarla con
`VERSION_STEP=x.y.z VERSION_STEP_CA=x.y.z ./01-instalar-step-ca.sh`).
Instala `step-cli` y `step-ca`, crea el usuario de servicio `step`,
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

### Vigencia de los certificados de dispositivo

Por defecto son 24 h, lo que hace innecesaria la revocacion: si un
dispositivo se pierde, basta con no renovarle.

Eso no sirve para equipos que pasan semanas sin conexion, porque step-ca
solo renueva certificados todavia vigentes. Para esos casos:

```bash
sudo ./07-vigencia-dispositivos.sh --dias 30
```

El script ajusta los claims del provisioner en `ca.json`, respalda la
configuracion, reinicia step-ca y **emite un certificado de prueba para
comprobar que la vigencia es la pedida**, revirtiendo si no lo es. Con
`--permitir-renovar-expirados` habilita `allowRenewalAfterExpiry`, util en
campo pero a cambio de que la caducidad deje de ser el mecanismo de baja:
use la lista blanca del API para eso. El detalle esta en
[`RASPBERRY.md`](RASPBERRY.md), punto 7.

## 2. API

```bash
sudo deploy/scripts/02-instalar-api.sh --dominio api.jocotoco.local
```

Hace lo siguiente:

- instala PHP 8.3, Apache, SQLite y Composer;
- crea el usuario de servicio `jocotoco`;
- crea `/var/lib/jocotoco{,/audio,/tmp}` (0750, del usuario `jocotoco`) y
  `/var/log/jocotoco`;
- copia el codigo a `/srv/jocotoco` e instala dependencias sin `--dev`;
- aplica las migraciones (`bin/jocotoco migrate`);
- habilita MPM event y los modulos de Apache necesarios, instala el pool de
  php-fpm, el `.ini` de OPcache, la conf de endurecimiento, la rotacion de
  logs y el temporizador de purga.

Antes de instalar nada comprueba que `apache2` y los paquetes `php8.3-*`
existan en los repositorios del sistema, y se detiene con instrucciones si
no (en Ubuntu 22.04 o anterior PHP 8.3 necesita el PPA de Ondrej Sury).

El VirtualHost queda en `sites-available` pero **no se habilita todavia**:
referencia el certificado del servidor y la raiz de la CA, y Apache se niega
a arrancar mientras esos archivos no existan. Lo habilita el paso 3, que es
quien los crea.

Dos usuarios distintos leen el arbol de codigo: php-fpm corre como
`jocotoco` y Apache como `www-data`. Por eso `/srv/jocotoco` queda de
`root:root` con permisos de solo lectura para todos (0755/0644) y los datos
quedan en `/var/lib/jocotoco` con 0750 del usuario `jocotoco`. Nunca guarde
secretos en el arbol de codigo: van en `/etc/jocotoco` o en el entorno del
pool.

### Activacion en un paso, con reversion automatica

En un servidor que ya sirve otros sitios, `05-activar-api.sh` hace la parte
delicada de una sola vez y **no deja Apache roto**:

```bash
sudo ./05-activar-api.sh --dominio dev.midominio.com --puerto 8444
```

1. Comprueba antes de tocar nada: aplicacion instalada, certificado emitido
   y con el SAN correcto, raiz de la CA, socket del pool de php-fpm, puerto
   libre y ausencia de otro VirtualHost con el mismo nombre y puerto. Si
   algo falta, no modifica nada y lo enumera.
2. Guarda el estado de Apache (sitios, confs y modulos habilitados) en
   `/var/backups/jocotoco-apache-<fecha>.txt`.
3. Genera el VirtualHost, lo habilita y valida la configuracion.
4. Verifica el API de extremo a extremo: `/v1/salud` debe dar 200 y
   `/v1/grabaciones` 403 sin certificado de cliente.
5. Si cualquiera de esos pasos falla, **deshabilita el sitio y restaura
   Apache**, confirmando que los demas sitios vuelven a servir.

No modifica el MPM, no deshabilita sitios ajenos y solo escribe en
`sites-available/jocotoco-api.conf` y su enlace en `sites-enabled`.

Probado con Apache 2.4.58 + php8.3-fpm en los cuatro escenarios: activacion
correcta, repeticion (idempotente), fallo de comprobacion previa y fallo de
verificacion con reversion (Apache vuelve a quedar activo).

### Si el servidor ya sirve otros sitios con Apache

El instalador **no modifica el MPM** ni deshabilita sitios ajenos. Cambiar
el MPM no se puede hacer en caliente: un `reload` posterior mata el
servicio con `AH00534: The MPM cannot be changed during restart` y se lleva
los sitios que ya estaban funcionando. Ademas un sitio que sirva PHP con
`mod_php` necesita `prefork`. El API no se ve afectado: usa `php8.3-fpm` a
traves de `mod_proxy_fcgi`, que funciona con cualquier MPM (verificado con
`prefork` y con `event`).

Por el mismo motivo, la conf de endurecimiento global
(`jocotoco-endurecimiento.conf`) se instala pero **no se habilita** cuando
hay otros sitios: cambia `ServerTokens`, `TraceEnable` y las `Options` de
`<Directory />` para todo el servidor. Revisela y actives con
`a2enconf jocotoco-endurecimiento` si le conviene.


Dos VirtualHost con el mismo `ServerName` en el mismo puerto no conviven:
Apache atiende con el primero que encuentra y el otro queda inerte. El
instalador lo detecta y se detiene antes de configurar nada. Hay dos salidas:

```bash
# a) un nombre propio para el API (recomendado si controla el DNS)
sudo ./02-instalar-api.sh --dominio api.midominio.com

# b) un puerto propio, sin tocar el sitio que ya usa el 443
sudo ./02-instalar-api.sh --dominio midominio.com --puerto 8444
```

**No use el 8443 para el API**: es el puerto por defecto de step-ca (lo fija
`01-instalar-step-ca.sh`). Antes de elegir, mire lo que ya escucha:

```bash
ss -ltnp | awk '{print $4, $6}'
```

El instalador se detiene si el puerto elegido lo ocupa un proceso que no es
Apache, antes de configurar nada.

Con `--puerto`, la directiva `Listen` se escribe **dentro del archivo del
sitio** (`sites-enabled` se incluye en el ambito global, asi que es valida
ahi). De ese modo `a2dissite jocotoco-api` retira tambien el `Listen` y
Apache vuelve a arrancar sin el API. Si estuviera en una conf aparte,
deshabilitar el sitio dejaria a Apache intentando escuchar un puerto que no
puede abrir, y no arrancaria ningun sitio del servidor.

Con `--puerto` el instalador ajusta el VirtualHost, agrega su `Listen` en
`conf-available/jocotoco-puerto.conf` y **elimina la redireccion del puerto
80**, que pertenece al otro sitio. Las Raspberry usan entonces
`JOCOTOCO_API_URL=https://midominio.com:8443`.

Verificado con Apache 2.4.58: un sitio existente en el 443 sigue
respondiendo igual mientras el API atiende en el 8443 con su propio mTLS.

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

- Apache: `LimitRequestBody` (en `deploy/apache/jocotoco-api.conf`)
- php-fpm: `upload_max_filesize` (≥ el limite) y `post_max_size` (algo mayor)
- `public/errores/413.json` si quiere cambiar el texto del rechazo

Tras cualquier cambio: `sudo systemctl reload php8.3-fpm apache2`.

## 3. Certificado TLS del servidor

```bash
sudo deploy/scripts/03-emitir-cert-servidor.sh \
     --dominio api.jocotoco.local \
     --ca-url https://ca.jocotoco.local:8443 \
     --huella <HUELLA_DE_LA_RAIZ>
```

Emite la hoja del servidor en `/etc/jocotoco/tls/`, publica la raiz en
`/etc/step/certs/root_ca.crt` (la que Apache usa para **validar clientes**),
habilita el sitio con `a2ensite`, aparta `000-default` si seguia activo (no
toca otros vhosts del servidor) y activa `jocotoco-cert-renew.timer`, que
renueva cada 8 h y recarga Apache.

```bash
sudo apache2ctl configtest && sudo systemctl reload apache2
curl -s --cacert /etc/step/certs/root_ca.crt \
     https://api.jocotoco.local/v1/salud | jq
```

### Como queda el mTLS

En `deploy/apache/jocotoco-api.conf`, a nivel de **VirtualHost**:

```apache
SSLCACertificateFile /etc/step/certs/root_ca.crt   # raiz de step-ca
SSLVerifyClient optional                           # /v1/salud sin cert
SSLVerifyDepth 2                                   # raiz + intermedia
SSLOptions +StdEnvVars +ExportCertData             # publica SSL_CLIENT_*
```

`SSLVerifyClient` **debe ir en el VirtualHost, no en un `<Directory>` ni en
un `<Location>`**: Apache 2.4 no implementa la autenticacion post-handshake
de TLS 1.3 y en TLS 1.3 no hay renegociacion, asi que pedir el certificado
por directorio solo funciona si la conexion cae a TLS 1.2. El certificado se
solicita una vez en el handshake y la autorizacion se decide despues:

```apache
<Location "/">
    Require expr "%{SSL:SSL_CLIENT_VERIFY} == 'SUCCESS'"
</Location>
<Location "/v1/salud">
    Require all granted
</Location>
```

`optional` permite que el monitoreo consulte `/v1/salud`; **la aplicacion lo
vuelve a comprobar**, de modo que si alguien despliega el API sin Apache
delante sigue exigiendo el certificado.

`SSLOptions +ExportCertData` es lo que entrega el PEM completo en
`SSL_CLIENT_CERT`; sin esa opcion la aplicacion no puede leer la identidad y
responde `401`. `mod_proxy_fcgi` pasa todas esas variables al pool de
php-fpm.

Las rutas llegan a `index.php` con `AliasMatch`, no con `FallbackResource` ni
`mod_rewrite`: esas dos hacen una redireccion interna que se autoriza contra
`/index.php`, con lo que `<Location "/v1/salud">` dejaria de aplicarse y la
sonda de salud empezaria a pedir certificado.

Los rechazos de Apache (403 sin certificado, 413 por `LimitRequestBody`) se
devuelven en `application/problem+json` mediante `ErrorDocument` y los
archivos de `public/errores/`, para que el cliente reciba siempre el mismo
formato de error.

## 4. Registrar dispositivos

Uno por Raspberry, en el servidor de la CA:

```bash
sudo deploy/scripts/04-registrar-dispositivo.sh rpi-yanacocha-01
```

Imprime un token valido **5 minutos** y el comando exacto a ejecutar en el
dispositivo. Continue en [`RASPBERRY.md`](RASPBERRY.md).

## 5. Verificacion

```bash
# Sin certificado: 403 (problem+json) en Apache
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

`02-instalar-api.sh` reinstala el pool de php-fpm desde la plantilla del
repositorio, pero **conserva** las variables `env[JOCOTOCO_*]` y el
`disable_functions` que ya estuvieran configurados, y deja un respaldo del
pool anterior con fecha. Sin eso, actualizar el codigo desactivaba en
silencio cosas configuradas despues, como la inscripcion automatica.


```bash
sudo deploy/scripts/02-instalar-api.sh --origen /ruta/al/repo   # rsync + composer + migrate
sudo systemctl reload php8.3-fpm                                # OPcache no valida timestamps
sudo systemctl reload apache2
```

## Problemas frecuentes

| Sintoma | Causa habitual |
|---------|----------------|
| `401` con `SSL_CLIENT_VERIFY=NONE` | El cliente no envio certificado, o `SSLVerifyClient` no esta activo |
| `403` "no fue emitido por la CA esperada" | `JOCOTOCO_CA_ISSUER_COMMON_NAME` no coincide con el CN de la intermedia (compruebe con `step certificate inspect`) |
| `403` "el certificado expiro" | El timer de renovacion del dispositivo no corre, o su reloj esta desfasado (`timedatectl`) |
| `403` "aun no es valido" | Reloj del servidor atrasado; la tolerancia es de 60 s (`JOCOTOCO_CERTIFICATE_CLOCK_SKEW_SECONDS`) |
| `413` con `maximo_bytes` | La aplicacion rechazo por `JOCOTOCO_MAX_UPLOAD_BYTES` |
| `413` sin cuerpo JSON | `LimitRequestBody` de Apache menor que el audio, o falta `ErrorDocument` |
| `403` de Apache en `/v1/salud` | Se movio `SSLVerifyClient` a un `<Directory>`/`<Location>`, o se cambio `AliasMatch` por `FallbackResource` |
| `401` con `SSL_CLIENT_CERT` vacio | Falta `SSLOptions +ExportCertData` en el VirtualHost |
| 403 "search permissions are missing on a component of the path" | Se quitaron los permisos de lectura de `/srv/jocotoco`: Apache (`www-data`) debe poder atravesar el DocumentRoot |
| `500` al subir | Permisos de `/var/lib/jocotoco/{audio,tmp}`: deben pertenecer a `jocotoco` |
| El cambio de una variable no toma efecto | Falta `systemctl reload php8.3-fpm`; verifique con `bin/jocotoco config` |
| `open_basedir` en los logs | Se movio el `data_dir` fuera de la lista del pool de php-fpm |
