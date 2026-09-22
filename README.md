# Jocotoco Audio API

API en **PHP 8.3** para **Ubuntu 24.04** que recibe archivos de audio enviados
desde dispositivos **Raspberry Pi**. Cada dispositivo se autentica con un
certificado de cliente emitido por **step-ca** (mTLS): no hay claves de API ni
contrasenas, la identidad es el certificado y la renueva `step-cli` sola.

```
  Raspberry Pi                         Servidor Ubuntu 24.04
 ┌────────────────────┐               ┌────────────────────────────────────┐
 │ arecord            │               │ Apache (TLS + SSLVerifyClient)     │
 │   ↓                │   HTTPS       │   ↓  SSL_CLIENT_* via mod_proxy_fcgi│
 │ cola en disco      │  mTLS  ─────► │ php8.3-fpm  →  public/index.php    │
 │   ↓                │               │   ↓                                │
 │ curl --cert --key  │               │ /var/lib/jocotoco/audio/<disp>/... │
 │ step ca renew (4h) │               │ SQLite (metadatos)                 │
 └────────────────────┘               └────────────────────────────────────┘
          ▲                                          ▲
          └────────── step-ca (CA interna) ──────────┘
```

## Que resuelve

- **Autenticacion por certificado**: Apache valida la cadena contra la raiz de
  step-ca y la aplicacion vuelve a comprobar emisor, vigencia e identidad.
- **Recepcion robusta de audio**: `multipart/form-data` o cuerpo binario, leido
  en bloques de 256 KiB (una grabacion de 64 MiB no se carga en memoria).
- **Validacion real del contenido**: el formato se detecta por bytes magicos,
  no por la extension ni por el `Content-Type` declarado.
- **Idempotencia**: el par (dispositivo, sha256) es unico, de modo que la
  Raspberry puede reintentar un envio sin duplicar grabaciones.
- **Escritura atomica**: se escribe un `.parcial` y se renombra, asi un envio
  cortado nunca deja un archivo a medias en el arbol de datos.

## Estructura

| Ruta | Contenido |
|------|-----------|
| `public/index.php` | Punto de entrada (php-fpm) |
| `src/Http/` | Request, Response, Router (errores RFC 7807) |
| `src/Security/` | Lectura del certificado de cliente y autenticacion mTLS |
| `src/Audio/` | Lectura del cuerpo, validacion, caso de uso de recepcion |
| `src/Storage/` | Archivos en disco, SQLite y migraciones |
| `src/Controller/` | Endpoints |
| `bin/jocotoco` | CLI: `migrate`, `config`, `inspeccionar-cert`, `listar`, `purgar` |
| `deploy/scripts/` | Instalacion de step-ca y del API en Ubuntu 24.04 |
| `deploy/raspberry/` | Cliente del dispositivo (step-cli + curl + systemd) |
| `deploy/apache/`, `deploy/php/`, `deploy/systemd/` | Configuracion de servicio |
| `docs/` | API, despliegue, dispositivos y decisiones de diseno |

## Puesta en marcha (resumen)

```bash
# 1. Servidor: CA interna
sudo deploy/scripts/01-instalar-step-ca.sh --ca-dns ca.jocotoco.local
#    Anote la huella de la raiz que imprime.

# 2. Servidor: API
sudo deploy/scripts/02-instalar-api.sh --dominio api.jocotoco.local
sudo deploy/scripts/03-emitir-cert-servidor.sh --dominio api.jocotoco.local

# 3. Por cada dispositivo (en el servidor de la CA)
sudo deploy/scripts/04-registrar-dispositivo.sh rpi-yanacocha-01

# 4. En la Raspberry Pi, con el token del paso anterior
sudo ./bootstrap-dispositivo.sh --nombre rpi-yanacocha-01 \
     --ca-url https://ca.jocotoco.local:8443 --huella <HUELLA> --token '<TOKEN>'

# 5. Enviar una grabacion
jocotoco-grabar-audio --segundos 60 --enviar
```

El detalle esta en [`docs/DESPLIEGUE.md`](docs/DESPLIEGUE.md) y
[`docs/RASPBERRY.md`](docs/RASPBERRY.md).

## Endpoints

| Metodo | Ruta | mTLS | Descripcion |
|--------|------|------|-------------|
| GET | `/v1/salud` | no | Estado del servicio (monitoreo) |
| GET | `/v1/yo` | si | Identidad y vigencia del certificado del dispositivo |
| GET | `/v1/limites` | si | Tamano maximo, formatos y cabeceras aceptadas |
| POST | `/v1/grabaciones` | si | Sube una grabacion |
| GET | `/v1/grabaciones` | si | Lista las grabaciones del dispositivo |
| GET | `/v1/grabaciones/{id}` | si | Metadatos de una grabacion |
| GET | `/v1/grabaciones/{id}/audio` | si | Descarga el audio |
| DELETE | `/v1/grabaciones/{id}` | si | Borra (deshabilitado por defecto) |
| GET | `/v1/dispositivos` | si | Inventario (solo clientes de consulta) |

Ejemplo de envio tal como lo hace el dispositivo:

```bash
curl -sS --cert /etc/jocotoco/tls/dispositivo.crt \
        --key  /etc/jocotoco/tls/dispositivo.key \
        --cacert /etc/jocotoco/tls/raiz-ca.crt \
        -X POST https://api.jocotoco.local/v1/grabaciones \
        -H "Content-Type: audio/wav" \
        -H "X-Audio-SHA256: $(sha256sum grabacion.wav | cut -d' ' -f1)" \
        -H "X-Grabado-En: 2026-09-22T05:30:00Z" \
        -H 'X-Metadatos: {"sitio":"Reserva Yanacocha","estacion":"E-07"}' \
        --data-binary @grabacion.wav
```

La referencia completa, con todos los codigos de error, esta en
[`docs/API.md`](docs/API.md).

## Configuracion

Todo se ajusta con variables de entorno `JOCOTOCO_*` (definidas en el pool de
php-fpm) sobre los valores de `config/config.php`:

| Variable | Defecto | Para que |
|----------|---------|----------|
| `JOCOTOCO_DATA_DIR` | `/var/lib/jocotoco` | Raiz de datos (audio, SQLite, temporales) |
| `JOCOTOCO_REQUIRE_MTLS` | `true` | Exigir certificado de cliente |
| `JOCOTOCO_CA_ISSUER_COMMON_NAME` | `*` | CN de la CA que debe haber firmado el certificado |
| `JOCOTOCO_ALLOWED_DEVICES` | vacio | Lista blanca (`rpi-uno,*.sensores.local`) |
| `JOCOTOCO_READER_DEVICES` | vacio | Clientes que pueden consultar todos los dispositivos |
| `JOCOTOCO_MAX_UPLOAD_BYTES` | `67108864` | Tamano maximo de grabacion |
| `JOCOTOCO_ALLOWED_FORMATS` | `wav,flac,ogg,opus,mp3` | Formatos aceptados |
| `JOCOTOCO_ALLOW_DELETE` | `false` | Habilitar `DELETE` |
| `JOCOTOCO_LOG_PATH` | `<data_dir>/logs/api.log` | Log JSON por linea (`stderr` para journald) |

`JOCOTOCO_MAX_UPLOAD_BYTES` debe ir acompanado de `LimitRequestBody` en
Apache y de `upload_max_filesize`/`post_max_size` en php-fpm.

## Desarrollo local

```bash
composer install
JOCOTOCO_DATA_DIR=./var JOCOTOCO_REQUIRE_MTLS=false php bin/jocotoco migrate
JOCOTOCO_DATA_DIR=./var JOCOTOCO_REQUIRE_MTLS=false composer serve
# La identidad se simula con una cabecera cuando mTLS esta apagado:
curl -s localhost:8080/v1/salud
curl -s -X POST localhost:8080/v1/grabaciones \
     -H 'X-Dispositivo-Dev: rpi-laboratorio' -H 'Content-Type: audio/wav' \
     --data-binary @grabacion.wav
```

`JOCOTOCO_REQUIRE_MTLS=false` es exclusivamente para desarrollo: cualquiera
puede declararse el dispositivo que quiera.

## Pruebas

```bash
composer test            # 92 casos
vendor/bin/phpunit --testsuite unit
vendor/bin/phpunit --testsuite integration
```

Las pruebas levantan una CA propia (ECDSA P-256, igual que step-ca) y emiten
certificados reales, de modo que la autenticacion se ejercita de verdad:
certificado de otra CA, expirado, aun no vigente, fuera de la lista blanca,
y los parametros `SSL_CLIENT_*` tal como los exporta Apache.

El montaje de Apache 2.4.58 + php8.3-fpm de Ubuntu 24.04 se verifico a mano
con una CA de prueba: sonda de salud sin certificado, `403` con
`problem+json` en las rutas protegidas, subida binaria y multipart, descarga,
aislamiento entre dispositivos, limite de tamano, rechazo de un certificado
de otra CA en el handshake, y autenticacion de cliente tanto en TLS 1.2 como
en TLS 1.3.

## Licencia

MIT.
