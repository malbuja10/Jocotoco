# Respuesta al contrato de integracion del monitor

Documento de vuelta para el equipo de la Raspberry. Responde punto por punto
la seccion 7 del *Contrato de integracion API | Monitor Jocotoco* v1.0
(14 de octubre de 2026) con los valores del servidor **dev.minkafab.com**.

> En el contrato las rutas apuntan a `https://192.168.1.88:8444`. Esa IP es
> un valor de desarrollo: la direccion real es la de este documento.

## 1. Direcciones definitivas

| Proposito | Metodo | URL completa | Variable del cliente |
|-----------|--------|--------------|----------------------|
| Inscripcion inicial | POST | `https://dev.minkafab.com:8444/v1/inscripcion` | `DEVICE_ENROLLMENT_URL` |
| Evento + WAV | POST | `https://dev.minkafab.com:8444/api/sincronizar` | `THREAT_API_URL` |
| Voltaje | POST | `https://dev.minkafab.com:8444/api/telemetry/voltage` | `VOLTAGE_API_URL` |
| Estado del dispositivo | POST | `https://dev.minkafab.com:8444/api/telemetry/status` | `DEVICE_TELEMETRY_API_URL` |
| Sonda de salud (sin certificado) | GET | `https://dev.minkafab.com:8444/v1/salud` | — |
| CA (solo `step`) | — | `https://dev.minkafab.com:8443` | `STEP_CA_URL` |

El puerto 8444 es del API; el 8443 es la propia step-ca. El 443 de ese
servidor sirve otros sitios y no responde a estas rutas. Ambos puertos deben
estar abiertos de salida en la red de la reserva (`ufw allow 8443,8444/tcp`
en el servidor; ya aplicado).

## 2. Certificado TLS del servidor y CA

El certificado del API lo emite la misma step-ca del proyecto, no una CA
publica: el dispositivo **debe** llevar instalada la raiz.

| Dato | Valor |
|------|-------|
| CA raiz | `Jocotoco Root CA` (ECDSA P-256) |
| CA intermedia que firma | `Jocotoco Intermediate CA` |
| Huella de la raiz (SHA-256) | `c77163bde598a9bdea25fb91c8544dad8c3269c05e7532c57ec91dae5c63fe91` |
| Archivo en el dispositivo | `/etc/jocotoco/root_ca.crt` (`STEP_CA_CERT`) |

La raiz se obtiene sin secretos, verificando la huella:

```bash
step ca bootstrap --ca-url https://dev.minkafab.com:8443 \
                  --fingerprint c77163bde598a9bdea25fb91c8544dad8c3269c05e7532c57ec91dae5c63fe91
cp ~/.step/certs/root_ca.crt /etc/jocotoco/root_ca.crt
```

El endpoint de inscripcion se sirve sobre ese mismo certificado, asi que el
`verify=` del cliente puede apuntar a `root_ca.crt` desde el primer momento,
como ya hace el codigo del monitor.

## 3. Politica mTLS

| Punto | Decision |
|-------|----------|
| CA que emite los certificados de cliente | `Jocotoco Intermediate CA`, provisioner JWK `dispositivos` |
| Vigencia del certificado del dispositivo | 30 dias por defecto, maximo 90; renovable antes de vencer y tambien despues (`allowRenewalAfterExpiry`), pensado para equipos sin Internet durante semanas |
| Identidad | primer SAN DNS del certificado; si no hay, el CN |
| Asociacion certificado ↔ `device_id` | el `device_id` del payload debe ser igual a esa identidad; si no, `403`. Si el payload no lo trae, se usa la del certificado |
| Validacion de expiracion | la hace Apache en el handshake y la repite el API (tolerancia de reloj: 60 s) |
| Emisor aceptado | solo certificados cuya cadena llega a la raiz configurada; cualquier otra CA falla en el handshake, antes de llegar al API |
| Revocacion | step-ca trabaja con revocacion pasiva: vigencias cortas y, en el servidor, lista de dispositivos permitidos (`JOCOTOCO_ALLOWED_DEVICES`) y bloqueo de altas (`bin/jocotoco bloquear-inscripcion`). Un equipo perdido se bloquea ahi y deja de renovar |
| Rutas sin certificado | solo `GET /v1/salud` y `POST /v1/inscripcion` |

## 4. Limites de payload y formato de errores

| Limite | Valor | Clave de configuracion |
|--------|-------|------------------------|
| Parte `data` del evento | 64 KiB | `event_max_data_bytes` |
| Archivo de audio | 64 MiB | `max_upload_bytes` |
| Duracion del WAV | 300 s | `event_max_duration_seconds` |
| Frecuencias aceptadas | 8000, 16000, 22050, 32000, 44100, 48000, 96000, 192000 Hz | `event_allowed_sample_rates` |
| Canales | hasta 2 | `event_max_channels` |
| Codificacion del WAV | PCM o IEEE float (no comprimido) | — |
| Registros por lote de telemetria | 1000 | `telemetry_max_records` |
| Cuerpo JSON de telemetria | 1 MiB | `telemetry_max_body_bytes` |

Los errores son `application/problem+json` (RFC 7807) y siempre traen
`type`, `title`, `status` y `detail` en castellano, mas los campos sueltos
que ayuden a corregir el envio (`maximo_sec`, `sample_rate`, `permitidas`…):

```json
{
  "type": "https://jocotoco.api/problems/entidad-no-procesable",
  "title": "Entidad no procesable",
  "status": 422,
  "detail": "El audio dura 420.0 s y el maximo es 300 s.",
  "duracion_sec": 420.0,
  "maximo_sec": 300
}
```

| Codigo | Cuando |
|--------|--------|
| `200` | reenvio de un evento ya guardado, o telemetria aceptada |
| `201` | evento nuevo guardado con su audio |
| `400` | falta una parte, JSON invalido, `event_id` mal formado, `timestamp` implausible |
| `401` | el certificado no llego verificado al API |
| `403` | sin certificado de cliente (lo corta Apache y devuelve el mismo `problem+json`), o `device_id` ajeno al certificado |
| `404` | ruta inexistente, o inscripcion deshabilitada |
| `413` | parte `data`, archivo o lote demasiado grandes |
| `415` | el archivo no es un WAV legible, o no es PCM |
| `422` | duracion, frecuencia, canales o metricas fuera de rango |
| `429` | demasiadas inscripciones del mismo equipo en una hora |
| `503` | base de datos o almacenamiento sin responder |

Nunca se devuelve `202` ni `204` como acuse de exito.

## 5. Unidades, husos y precision

| Campo | Unidad acordada |
|-------|-----------------|
| `timestamp` (evento y telemetria) | epoch Unix en **segundos**, UTC. Se aceptan milisegundos y se convierten; se rechaza lo anterior al ano 2000 o mas de 5 minutos en el futuro |
| `cpu_temp` | **grados Celsius**; se acepta de -50 a 150 |
| `cpu_usage_pct`, `ram_usage_pct`, `disk_usage_pct` | porcentaje 0–100 |
| `disk_free_gb` | GB |
| `uptime_hours` | horas |
| `value` (voltaje) | voltios, con decimales; se guarda como `REAL` (doble precision), sin redondear |
| `duration_sec`, `sample_rate`, `channels`, `bit_depth` | se guardan los que trae la cabecera del WAV, no los declarados |
| Fechas en las respuestas | ISO 8601 en UTC (`2026-10-14T12:26:40Z`) |

El servidor mantiene su reloj con NTP; las marcas `recibido_en` son suyas y
las `timestamp`/`ocurrido_en`, del dispositivo. Guardar las dos permite
detectar un equipo con el reloj corrido sin perder el dato.

## 6. Persistencia, deduplicacion y trazabilidad

- `200`/`201` se devuelven **despues** de escribir el audio en disco (archivo
  temporal y `rename`, que es atomico) y de confirmar la transaccion SQLite
  en modo WAL. Si cualquiera de los dos falla, el codigo es `5xx` y el
  monitor conserva su copia.
- **Eventos:** clave logica `device_id` + `event_id`, con indice unico. Un
  reenvio devuelve `200`, la cabecera `Idempotent-Replay: true` y el evento
  que ya estaba, con su mismo identificador interno. Dos dispositivos pueden
  usar el mismo `event_id` sin estorbarse.
- **Audio:** ademas se deduplica por sha256 dentro del dispositivo; dos
  eventos con el mismo WAV comparten un unico archivo.
- **Telemetria:** clave `device_id` + `timestamp` por tipo de metrica (tablas
  separadas para voltaje y estado), tambien con indice unico. La respuesta
  dice cuantos registros se guardaron y cuantos ya existian; en ambos casos
  es `200` y el monitor puede borrarlos.
- Un lote de telemetria con un registro invalido se rechaza **entero**: como
  el monitor borra lo que sincroniza al recibir `200`, un exito parcial le
  haria perder los registros descartados.
- Trazabilidad: cada evento queda con su `event_id`, el ULID interno, el
  `payload` original completo y el enlace a su audio. `GET /v1/eventos/{id}`
  los devuelve juntos.

## 7. Retencion

| Dato | Retencion | Donde se cambia |
|------|-----------|-----------------|
| Audio y metadatos de grabacion | 365 dias | `jocotoco-purga.timer` → `bin/jocotoco purgar <dias>` |
| Eventos de deteccion | la misma que el audio | idem |
| Voltaje y estado | la misma | idem |
| Log del API (JSON) | rotacion diaria, 30 archivos | `/etc/logrotate.d/jocotoco` |

La purga corre a diario y borra por fecha de recepcion. Si la reserva
necesita conservar los eventos mas tiempo que el audio, se separa en dos
plazos: digalo y se ajusta.

## 8. Credencial de inscripcion

El secreto de fabrica viaja por canal seguro (gestor de contrasenas o
`step` con el token ya emitido), nunca en este documento, en el repositorio
ni en los logs: el API registra el intento de alta y el resultado, jamas el
secreto ni el token. Detalle del flujo en [`INSCRIPCION.md`](INSCRIPCION.md).

Limites vigentes: hasta 5 tokens por dispositivo y hora (`429` al pasarse),
10 fallos por IP y hora, y tokens de **60 minutos**, pensados para un enlace
satelital lento. El alta esta deshabilitada por defecto
(`JOCOTOCO_ENROLLMENT_ENABLED=true` la activa).

## 9. Lo que el servidor no espera recibir

Se da por bueno el punto 6 del contrato: el API no exige identificador ni
version del modelo, conjunto de clases, bounding box, espectrograma,
latencia, piso de ruido ni motivo de activacion de antena. Si algun dia el
monitor los envia dentro de `data`, se guardan tal cual en el `payload`
original del evento sin necesidad de cambiar el servidor.

## 10. Especificacion formal

[`openapi.yaml`](openapi.yaml) describe las rutas, los esquemas y los codigos
de error. Se puede abrir en Swagger UI o importar en Postman.
