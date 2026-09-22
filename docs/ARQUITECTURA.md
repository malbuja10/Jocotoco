# Arquitectura y decisiones de diseno

## Flujo de una grabacion

```
arecord  →  /var/lib/jocotoco/cola/<disp>-<marca>.wav      (Raspberry)
              │  .parcial mientras graba
              ▼
jocotoco-enviar-audio  ──curl --cert/--key──►  Apache :443
                                                 │  valida la cadena contra
                                                 │  la raiz de step-ca
                                                 ▼
                                    php8.3-fpm / public/index.php
                                                 │
   MtlsAuthenticator ◄── SSL_CLIENT_VERIFY / SSL_CLIENT_CERT
        │ identidad = SAN DNS (o CN)
        ▼
   PayloadReader   → archivo temporal, leido en bloques de 256 KiB
        ▼
   AudioValidator  → tamano, bytes magicos, sha256 declarado
        ▼
   RecordingRepository.findByChecksum  → si existe: 200 idempotente
        ▼
   AudioStorage.store  → <raiz>/<disp>/<aaaa>/<mm>/<dd>/<ulid>.<ext>
        ▼                  (escribe .parcial y renombra)
   RecordingRepository.save  → SQLite (indice unico disp+sha256)
        ▼
   201 Created + Location + ETag
```

## Decisiones

### Sin framework

La aplicacion no tiene dependencias de runtime: solo extensiones estandar de
PHP (`json`, `openssl`, `pdo_sqlite`). Un API que expone siete rutas no
justifica el costo de mantener y actualizar un framework en equipos que a
veces pasan meses sin tocarse. PHPUnit es la unica dependencia, y de
desarrollo. La capa HTTP propia (`src/Http/`) es deliberadamente pequena y
sustituible por PSR-7/PSR-15 si el proyecto crece.

### La autenticacion se comprueba dos veces

Apache valida la cadena y la aplicacion vuelve a verificar emisor, vigencia e
identidad. Es redundante a proposito:

- si alguien despliega el API sin Apache delante (contenedor, prueba, otro
  proxy), sigue exigiendo certificado;
- `SSLVerifyClient optional` es necesario para que `/v1/salud` responda al
  monitoreo, y esa flexibilidad no debe convertirse en un API abierto;
- la lista blanca y el CN del emisor son politica de la aplicacion, no del
  servidor web, y cambian sin recargar Apache.

### Por que el mTLS se declara en el VirtualHost y no por directorio

Apache 2.4 no implementa la autenticacion post-handshake de TLS 1.3, y en
TLS 1.3 no existe renegociacion: un `SSLVerifyClient require` dentro de un
`<Directory>` o `<Location>` solo funciona si la conexion cae a TLS 1.2. Por
eso el certificado se solicita una sola vez, en el handshake inicial, con
`SSLVerifyClient optional` a nivel de VirtualHost, y la decision de
autorizacion se toma despues con `Require expr "%{SSL:SSL_CLIENT_VERIFY} ==
'SUCCESS'"`, que no necesita renegociar nada. Se verifico que el esquema
funciona igual en TLS 1.2 y en TLS 1.3.

Por el mismo motivo las rutas se mapean a `index.php` con `AliasMatch` en
lugar de `FallbackResource` o `mod_rewrite`: esas dos producen una
redireccion interna, y la peticion interna se autoriza contra la URL nueva
(`/index.php`), de modo que `<Location "/v1/salud">` dejaria de aplicarse y
la sonda de salud empezaria a exigir certificado.

La tolerancia de reloj (60 s por defecto) se aplica solo a `notBefore`: un
certificado recien emitido puede llegar con fecha unos segundos adelante del
reloj del servidor. No se tolera nada en `notAfter`.

### El formato se detecta por bytes magicos

Un dispositivo puede mentir en la extension y en el `Content-Type`. El
`AudioSniffer` lee los primeros 64 bytes y reconoce contenedores reales
(RIFF/WAVE, fLaC, OggS con o sin OpusHead, ID3 y sincronizacion de trama
MPEG, ADTS de AAC, `ftyp` de MP4). Si el `Content-Type` declarado contradice
al contenido, el envio se rechaza con `415` en lugar de guardar un archivo
mal etiquetado.

Distinguir MP3 de AAC/ADTS exige mirar los bits de capa del segundo byte:
`0xFF` seguido de capa `00` es ADTS; cualquier otra capa es MPEG audio.

### Idempotencia por (dispositivo, sha256)

Una Raspberry con enlace intermitente reintenta. Si el reintento llega
despues de que el primer envio se completara, el indice unico
`(device_id, sha256)` evita el duplicado y el API responde `200` con la
grabacion existente e `Idempotent-Replay: true`. La carrera entre dos envios
simultaneos identicos se resuelve en la base: si el `INSERT` falla, se
consulta de nuevo por checksum y se borra el archivo recien escrito.

El cliente puede ademas declarar `X-Audio-SHA256`; si no coincide con lo
recibido, nada se almacena. Es la unica forma de detectar una transferencia
truncada, porque `Content-Length` puede llegar correcto y el cuerpo no.

### Streaming, no memoria

El cuerpo binario se copia al archivo temporal en bloques de 256 KiB con un
tope acumulado; `mod_proxy_fcgi` pasa el cuerpo a php-fpm en streaming, sin
guardar otra copia. Una grabacion de 64 MiB se procesa con unos pocos MB de
memoria en PHP. La descarga usa `fpassthru` por el mismo motivo.

### Escritura atomica y rutas saneadas

El archivo se escribe como `<destino>.parcial` y se renombra (`rename` es
atomico dentro del mismo sistema de archivos): un envio cortado no deja
archivos a medias visibles. El nombre del dispositivo viene del certificado,
asi que se sanea antes de usarlo como directorio (se colapsan `..`, se
sustituye todo lo que no sea `[A-Za-z0-9._-]`), y `AudioStorage::absolutePath`
comprueba con `realpath` que la ruta final siga dentro del arbol de datos.

### SQLite

El audio vive en disco y la base solo guarda metadatos: unos pocos cientos de
bytes por grabacion, escritos por decenas de dispositivos. SQLite en modo WAL
con `busy_timeout` cubre ese perfil sin operar un servidor de base de datos
en el nodo. Si mas adelante se necesita PostgreSQL, el cambio esta contenido
en `Storage/Database.php` y `Storage/RecordingRepository.php`.

Las migraciones son archivos `.sql` numerados en `database/migrations/`,
aplicados en una transaccion y registrados en `schema_migrations`.

### ULID en lugar de UUID

Los identificadores son ULID: 26 caracteres en base32 de Crockford, con los
primeros 10 derivados del milisegundo de llegada. Ordenar por `id` equivale a
ordenar por momento de recepcion, lo que ahorra indices y hace legibles los
nombres de archivo. El alfabeto excluye `I`, `L`, `O` y `U`, asi que no hay
ambiguedad al dictarlos ni palabras indeseadas.

### Certificados de 24 horas

Con vigencia corta, la revocacion deja de ser un problema operativo: un
dispositivo perdido simplemente deja de renovar. Por eso el temporizador de
renovacion (cada 4 h, renueva con menos de 8 h restantes) es parte del
bootstrap y no un paso opcional, y por eso `/v1/yo` informa
`expira_en_segundos`.

### Errores como problem+json

Todas las respuestas de error siguen RFC 7807 con `detail` en espanol y
campos adicionales utiles para el cliente (`maximo_bytes`,
`formatos_permitidos`, `sha256_calculado`). El script de la Raspberry
distingue por codigo entre rechazo definitivo (no reintentar) y fallo
temporal (reintentar), que es la decision que realmente necesita tomar.

## Limites conocidos

- **Un solo nodo.** El almacenamiento es el sistema de archivos local y la
  base es SQLite. Para varios nodos hay que mover el audio a
  almacenamiento compartido (NFS, S3) y los metadatos a PostgreSQL.
- **Sin CRL activa.** La configuracion de Apache trae `SSLCARevocationFile`
  comentado. Con
  certificados de 24 h no suele hacer falta; si su politica la exige,
  publique la CRL de step-ca y descomente esa linea.
- **Sin control de cuota por dispositivo.** El API limita el tamano de cada
  grabacion, no el volumen acumulado. La retencion se gestiona con
  `bin/jocotoco purgar` (temporizador diario).
- **Sin analisis de audio.** El API valida contenedores; no calcula duracion
  ni espectro. `duracion_segundos` y demas llegan como metadatos declarados
  por el dispositivo.
