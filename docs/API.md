# Referencia del API

Base en el despliegue actual: `https://dev.minkafab.com:8444`
(las rutas del contrato del monitor cuelgan de `/api`, las de consulta y
operacion de `/v1`).
Autenticacion: TLS mutuo con certificado emitido por step-ca. La identidad del
dispositivo es el primer SAN DNS del certificado y, si no existe, su CN.

Los errores usan `application/problem+json` (RFC 7807):

```json
{
  "type": "https://jocotoco.api/problems/media-type",
  "title": "Tipo de contenido no soportado",
  "status": 415,
  "detail": "El contenido no corresponde a un formato de audio reconocido.",
  "formatos_permitidos": ["wav", "flac", "ogg", "opus", "mp3"]
}
```

Toda respuesta incluye `X-Request-Id` (se respeta el que envie el cliente),
`X-Content-Type-Options: nosniff` y `Cache-Control: no-store`.

---

## GET /v1/salud

Unica ruta que no exige certificado de cliente, para que el monitoreo la
consulte sobre TLS. Devuelve `200` si la base y el almacenamiento responden,
`503` si alguno falla.

```json
{
  "estado": "ok",
  "version": "1.0.0",
  "hora": "2026-09-22T05:30:00Z",
  "php": "8.3.6",
  "comprobaciones": { "base_datos": "ok", "almacenamiento": "ok" },
  "almacenamiento": { "raiz": "/var/lib/jocotoco/audio", "bytes_libres": 84288765952 },
  "totales": { "grabaciones": 1420, "bytes": 8123456789, "dispositivos": 6 }
}
```

## POST /v1/inscripcion

Alta automatica de dispositivos: entrega un token de un solo uso de step-ca
a cambio del secreto de fabrica. No exige certificado de cliente (el equipo
que se inscribe todavia no tiene ninguno) y **viene deshabilitada**,
respondiendo `404` mientras no se configure.

El contrato completo, el cliente en Python y los limites del esquema estan
en [`INSCRIPCION.md`](INSCRIPCION.md).

## GET /v1/yo

Como ve el API al dispositivo. Es la forma recomendada de comprobar desde la
Raspberry que su certificado sirve y cuanto le queda de vigencia.

```json
{
  "dispositivo": "rpi-yanacocha-01",
  "certificado": {
    "identidad": "rpi-yanacocha-01",
    "common_name": "rpi-yanacocha-01",
    "issuer_dn": "CN=Jocotoco Intermediate CA,O=Jocotoco",
    "numero_serie": "2f8c...",
    "huella_sha256": "b4c81e...",
    "valido_desde": "2026-09-22T00:00:00+00:00",
    "valido_hasta": "2026-09-23T00:00:00+00:00",
    "sans_dns": ["rpi-yanacocha-01"]
  },
  "expira_en_segundos": 58200,
  "historial": {
    "primera_conexion": "2026-03-04T11:20:01Z",
    "ultima_conexion": "2026-09-22T05:29:58Z",
    "grabaciones": 312,
    "bytes_almacenados": 2145389012
  }
}
```

## GET /v1/limites

Lo que el dispositivo necesita saber antes de enviar: `maximo_bytes`,
`minimo_bytes`, `formatos_permitidos`, `campo_multipart` y las cabeceras
opcionales reconocidas.

## POST /v1/grabaciones

Sube una grabacion. Dos formas equivalentes:

**Cuerpo binario** (recomendado para dispositivos, no duplica el archivo en
memoria ni en disco):

```
POST /v1/grabaciones
Content-Type: audio/wav
X-Audio-SHA256: 3c76bf7c...
X-Grabado-En: 2026-09-22T05:30:00Z
X-Nombre-Archivo: yanacocha-20260922T0530Z.wav
X-Metadatos: {"sitio":"Reserva Yanacocha","estacion":"E-07","latitud":-0.1236}

<bytes del audio>
```

**multipart/form-data** con el campo `audio` y los metadatos como campos
normales (`grabado_en`, `sitio`, `estacion`, `nota`, `especie`,
`duracion_segundos`, `frecuencia_muestreo`, `canales`, `ganancia_db`,
`latitud`, `longitud`).

### Cabeceras opcionales

| Cabecera | Efecto |
|----------|--------|
| `X-Audio-SHA256` | Si no coincide con lo recibido, el envio se rechaza con `422` y nada se almacena |
| `X-Grabado-En` | Fecha ISO 8601 de la grabacion; se normaliza a UTC |
| `X-Nombre-Archivo` | Nombre original que se conserva en los metadatos |
| `X-Metadatos` | Objeto JSON con los metadatos de arriba |
| `X-Request-Id` | Se refleja en la respuesta y en el log |

Los metadatos tambien pueden ir en la query string (`?sitio=Mindo`).

### Respuestas

| Codigo | Significado |
|--------|-------------|
| `201` | Almacenada. `Location` apunta al recurso, `ETag` es el sha256 |
| `200` | El dispositivo ya habia subido ese mismo audio: se devuelve el existente con `Idempotent-Replay: true` y `duplicado: true` |
| `400` | Cuerpo vacio, campo `audio` ausente, `X-Metadatos` invalido, fecha o metadato mal formado |
| `401` | Sin certificado de cliente o Apache no lo verifico |
| `403` | Certificado de otra CA, expirado, aun no vigente o dispositivo no autorizado |
| `413` | Supera `JOCOTOCO_MAX_UPLOAD_BYTES` |
| `415` | El contenido no es audio reconocible, o el formato esta deshabilitado, o contradice el `Content-Type` declarado |
| `422` | Archivo demasiado pequeno, o el sha256 declarado no coincide |
| `500` | Fallo al escribir en disco o en la base |

```json
{
  "mensaje": "Grabacion almacenada.",
  "duplicado": false,
  "grabacion": {
    "id": "01M33K2HFNZBNYQB8QT71R891S",
    "dispositivo": "rpi-yanacocha-01",
    "formato": "wav",
    "tipo_medio": "audio/wav",
    "tamano_bytes": 16044,
    "sha256": "3c76bf7c...",
    "nombre_original": "yanacocha-20260922T0530Z.wav",
    "grabado_en": "2026-09-22T05:30:00Z",
    "recibido_en": "2026-09-22T05:31:12Z",
    "huella_certificado": "b4c81e...",
    "metadatos": { "sitio": "Reserva Yanacocha", "estacion": "E-07" },
    "enlaces": {
      "detalle": "/v1/grabaciones/01M33K2HFNZBNYQB8QT71R891S",
      "descarga": "/v1/grabaciones/01M33K2HFNZBNYQB8QT71R891S/audio"
    }
  }
}
```

El `id` es un ULID: 26 caracteres ordenables por tiempo, asi que ordenar por
`id` equivale a ordenar por momento de llegada.

## GET /v1/grabaciones

Lista las grabaciones **del dispositivo que consulta**. Un cliente incluido en
`JOCOTOCO_READER_DEVICES` ve todas y puede filtrar con `?dispositivo=`.

| Parametro | Defecto | Rango |
|-----------|---------|-------|
| `limite` | 50 | 1–200 |
| `desplazamiento` | 0 | ≥ 0 |
| `desde` | — | Fecha ISO 8601; devuelve lo recibido desde entonces |
| `dispositivo` | — | Solo para clientes de consulta |

## GET /v1/grabaciones/{id}

Metadatos de una grabacion. `403` si pertenece a otro dispositivo, `404` si no
existe, `400` si el identificador no es un ULID valido.

## GET /v1/grabaciones/{id}/audio

Descarga el archivo con su `Content-Type` real, `Content-Disposition` de
adjunto y `X-Audio-SHA256`. Si se envia `If-None-Match` con el sha256 del
archivo, responde `304`.

## DELETE /v1/grabaciones/{id}

Deshabilitado por defecto (`403`). Con `JOCOTOCO_ALLOW_DELETE=true` borra el
registro y el archivo y responde `204`.

## GET /v1/dispositivos

Inventario de dispositivos vistos (primera y ultima conexion, huella del
ultimo certificado, totales). Solo para `JOCOTOCO_READER_DEVICES`; el resto
recibe `403`.

---

# Rutas del contrato del monitor

Las tres rutas que usa el monitor de campo (ver
[`CONTRATO-MONITOR.md`](CONTRATO-MONITOR.md)). Todas exigen certificado de
cliente y responden `200`/`201` **solo** cuando el dato ya esta guardado de
forma duradera; nunca `202` ni `204`, que el monitor no reconoce como exito.

## POST /api/sincronizar

Evento de deteccion con su audio, en `multipart/form-data`:

| Parte | Tipo | Contenido |
|-------|------|-----------|
| `data` | campo de texto | objeto JSON serializado (maximo 64 KiB) |
| `audio` | archivo | WAV PCM, `audio/wav` |

Campos de `data`:

| Campo | Tipo | Obligatorio | Notas |
|-------|------|-------------|-------|
| `event_id` | string | si | 8 a 64 caracteres `A-Za-z0-9 . _ : -`; un UUID encaja |
| `device_id` | string | no | si viene, debe coincidir con el certificado (`403` si no) |
| `threat_type` | string | no | etiqueta del modelo, se guarda literal (maximo 64) |
| `confidence_score` | number | no | `400` si no es numerico |
| `audio_path` | string | no | ruta en la Raspberry; se guarda como referencia y nunca se lee |
| `timestamp` | number | no | epoch UTC en segundos (se aceptan milisegundos); si falta, la hora del servidor |
| `duration_sec`, `sample_rate`, `channels`, `bit_depth` | number | no | se comparan con el WAV; manda el archivo |

Limites del audio: WAV PCM o IEEE float, duracion util entre 0 y
`event_max_duration_seconds` (300 s), frecuencia dentro de
`event_allowed_sample_rates` (8000, 16000, 22050, 32000, 44100, 48000, 96000
y 192000 Hz) y hasta `event_max_channels` (2) canales.

```bash
curl --cert /etc/jocotoco/device.crt --key /etc/jocotoco/device.key \
     --cacert /etc/jocotoco/root_ca.crt \
     -F 'data={"event_id":"7f4c1d62-9a84-4c3d-9d2e-1b0f5a6c7e81","threat_type":"chainsaw","confidence_score":0.87,"timestamp":1792000000}' \
     -F 'audio=@/home/pi/monitor/audio/evento.wav;type=audio/wav' \
     https://dev.minkafab.com:8444/api/sincronizar
```

Respuesta `201` (evento nuevo) o `200` (reenvio del mismo
`device_id` + `event_id`, con la cabecera `Idempotent-Replay: true`):

```json
{
  "status": "ok",
  "duplicado": false,
  "evento": {
    "id": "01K7A0W8Q3M6V2S4R9N1T5YH7C",
    "event_id": "7f4c1d62-9a84-4c3d-9d2e-1b0f5a6c7e81",
    "device_id": "rpi-yanacocha-01",
    "threat_type": "chainsaw",
    "confidence_score": 0.87,
    "timestamp": 1792000000,
    "ocurrido_en": "2026-10-14T12:26:40Z",
    "audio": {
      "grabacion_id": "01K7A0W8Q3C8D0F2H4J6L8N0P2",
      "duration_sec": 2.0,
      "sample_rate": 16000,
      "channels": 1,
      "bit_depth": 16,
      "descarga": "/v1/grabaciones/01K7A0W8Q3C8D0F2H4J6L8N0P2/audio"
    },
    "recibido_en": "2026-10-14T12:27:03Z"
  }
}
```

Los valores de `audio` salen de la cabecera del WAV, no del JSON: si el
monitor declara otra cosa, el evento se acepta igual y la diferencia queda
anotada en el log del servidor.

Errores: `400` (falta `data` o `audio`, JSON invalido, `event_id` mal
formado, `timestamp` implausible), `403` (sin certificado de cliente —lo
corta Apache— o `device_id` ajeno al certificado),
`413` (parte `data` demasiado grande), `415` (no es un WAV legible o no es
PCM), `422` (duracion, frecuencia o canales fuera de limites).

## POST /api/telemetry/voltage

```json
{ "voltage": [ { "timestamp": 1792000000, "value": 12.4, "device_id": "rpi-yanacocha-01" } ] }
```

`timestamp` en epoch UTC (segundos; se aceptan milisegundos), `value` en
voltios entre -1000 y 1000, `device_id` opcional y, si viene, igual al del
certificado. Hasta `telemetry_max_records` (1000) registros y
`telemetry_max_body_bytes` (1 MiB) por solicitud.

## POST /api/telemetry/status

```json
{
  "device_telemetry": [
    {
      "timestamp": 1792000000,
      "cpu_temp": 48.2,
      "cpu_usage_pct": 14.5,
      "ram_usage_pct": 37.1,
      "disk_free_gb": 21.8,
      "disk_usage_pct": 32.0,
      "uptime_hours": 76.4,
      "device_id": "rpi-yanacocha-01"
    }
  ]
}
```

Solo `timestamp` es obligatorio; el resto puede faltar y se guarda como nulo.
Rangos aceptados: `cpu_temp` -50 a 150 °C, los `*_pct` de 0 a 100,
`disk_free_gb` y `uptime_hours` de 0 a 1 000 000.

### Respuesta de las dos rutas de telemetria

```json
{ "status": "ok", "recibidas": 12, "guardadas": 10, "duplicadas": 2 }
```

Siempre `200`. `duplicadas` son los registros que ya existian con el mismo
`device_id` + `timestamp`: reenviar un lote es seguro.

Un lote con **un solo** registro invalido se rechaza entero (`400`, `403`,
`413` o `422` segun el caso) y no se guarda nada. Es deliberado: el monitor
borra sus datos locales al recibir `200`, asi que un exito parcial le haria
perder los registros descartados.

## GET /v1/eventos

Eventos del dispositivo que consulta. Parametros: `limite` (1–200, defecto
50), `desplazamiento` y `clase` (filtra por `threat_type`).

```json
{ "total": 37, "limite": 50, "desplazamiento": 0, "eventos": [ ... ] }
```

## GET /v1/eventos/{id}

Un evento con su `payload_original` (el JSON tal cual lo envio el monitor) y
`ruta_declarada_en_el_dispositivo`. `403` si es de otro dispositivo, `404` si
no existe.

## GET /v1/telemetria

Lo ultimo recibido del dispositivo que consulta: `voltaje` y `estado`, con
`limite` registros de cada uno (1–200, defecto 20).
