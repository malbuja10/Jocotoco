# Referencia del API

Base: `https://api.jocotoco.local/v1`
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
