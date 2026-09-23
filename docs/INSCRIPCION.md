# Alta automatica de dispositivos (`POST /v1/inscripcion`)

Un dispositivo recien instalado no tiene certificado, asi que no puede
autenticarse por mTLS. Este endpoint le entrega un **token de un solo uso**
de step-ca a cambio de un **secreto de fabrica**; con ese token el propio
dispositivo pide su certificado con `step ca certificate`.

Es, junto con `/v1/salud`, la unica ruta que no exige certificado de
cliente. **Viene deshabilitada** y responde `404` mientras no se configure.

---

## Contrato

```
POST /v1/inscripcion
Content-Type: application/json

{"device_id": "rpi-yanacocha-01", "factory_secret": "sk_factory_..."}
```

Respuesta `200`:

```json
{
  "token": "eyJhbGciOiJFUzI1NiIs...",
  "device_id": "rpi-yanacocha-01",
  "expira_en": "60m",
  "ca_url": "https://dev.midominio.com:8443",
  "huella_raiz": "c77163bde598a9bdea25fb91c8544dad8c3269c05e7532c57ec91dae5c63fe91",
  "tokens_restantes": 1
}
```

El cliente solo necesita `token`; los demas campos le ahorran llevar la URL
y la huella de la CA en su propia configuracion.

| Codigo | Significado | Que hacer en el dispositivo |
|--------|-------------|------------------------------|
| `200` | Token emitido | Usarlo de inmediato (vence en 60 min) |
| `400` | Cuerpo no es JSON, o `device_id` no es un hostname en minusculas | Corregir el cliente; no reintentar |
| `403` | Secreto incorrecto, dispositivo fuera de la lista, o bloqueado | No reintentar: requiere intervencion |
| `404` | La inscripcion automatica esta deshabilitada | No reintentar |
| `429` | Demasiados tokens para ese dispositivo, o demasiados intentos fallidos desde esa IP | Esperar (ventana de 1 h) o pedir el reinicio al operador |
| `500` | La CA no pudo emitir el token | Reintentar con espera progresiva |

Se aceptan tambien las claves `dispositivo` y `secreto_fabrica` como
sinonimos de `device_id` y `factory_secret`.

---

## Habilitarlo en el servidor

```bash
sudo ./08-habilitar-inscripcion.sh \
     --secreto 'sk_factory_9f83a02b11c' \
     --dominio dev.midominio.com --puerto 8444

# o dejando que genere el secreto:
sudo ./08-habilitar-inscripcion.sh --generar-secreto --dominio dev.midominio.com
```

Opcionalmente, `--dispositivos "rpi-yanacocha-01,rpi-mindo-02"` restringe
quien puede inscribirse, y `--max-tokens N` cambia cuantos tokens puede
pedir un dispositivo **por hora** (5 por defecto).

El tope es por ventana de tiempo, no de por vida: el token se gasta aunque
el `step ca certificate` del dispositivo falle despues, y un equipo no debe
quedarse fuera por dos intentos con un error de configuracion.

El script instala un envoltorio con privilegios y una regla de `sudo`
acotada a ese unico comando, de modo que **el API nunca tiene la contrasena
del provisioner de la CA**; ajusta el pool de php-fpm; aplica la migracion;
y comprueba el flujo completo pidiendo un token de prueba, que deja
bloqueado al terminar.

### Operacion

```bash
sudo -u jocotoco php /srv/jocotoco/bin/jocotoco inscripciones
sudo -u jocotoco php /srv/jocotoco/bin/jocotoco bloquear-inscripcion rpi-perdida "equipo extraviado"

# Le devuelve la posibilidad de inscribirse (desbloquea y olvida sus tokens)
sudo -u jocotoco php /srv/jocotoco/bin/jocotoco reiniciar-inscripcion rpi-yanacocha-01
```

Cada intento, aceptado o no, queda en la tabla `enrollment_attempts` con IP
y motivo:

```bash
sudo -u jocotoco sqlite3 /var/lib/jocotoco/jocotoco.sqlite \
  "SELECT created_at, device_id, ip, result, detail FROM enrollment_attempts ORDER BY id DESC LIMIT 20;"
```

---

## Cliente en Python

Si el dispositivo lo gestiona su propio programa en Python, en
[`deploy/raspberry/python/`](../deploy/raspberry/python/) hay un modulo que
cubre las tres cosas del ciclo de vida (alta, renovacion y envio con cola) y
que se importa directamente:

```python
from jocotoco_client import ClienteJocotoco, ConfiguracionCliente

cliente = ClienteJocotoco(ConfiguracionCliente.desde_entorno())
cliente.asegurar_identidad()
cliente.renovar_si_hace_falta()
cliente.procesar_cola()
```

Su `README.md` tiene la instalacion y el temporizador de systemd. Lo que
sigue es la version minima del alta, para integrarla en codigo existente.

Esta es la version ajustada del cliente que ya existe en el dispositivo. Los
cambios respecto al original, y por que:

- **El secreto no va en el codigo**: se lee del entorno o de un archivo con
  permisos restringidos. Un secreto en el repositorio acaba en todas las
  copias del proyecto, no solo en las imagenes de los equipos.
- **`step ca certificate` recibe `--ca-url` y `--root`** tomados de la
  respuesta. Sin ellos, el comando depende de que el equipo ya tenga hecho
  `step ca bootstrap`; con ellos funciona en un equipo limpio.
- **Se distingue el error definitivo del temporal** por codigo HTTP: con
  `403`/`404`/`409` no tiene sentido reintentar en bucle.
- **`__name__`**, con dos guiones bajos a cada lado, en `get_logger`.

```python
import os
import subprocess

import requests

import config
from logging_config import get_logger

logger = get_logger(__name__)

# El secreto se inyecta al aprovisionar la imagen, no se escribe aqui.
FACTORY_CLAIM_SECRET = os.environ.get('JOCOTOCO_FACTORY_SECRET') or (
    open('/etc/jocotoco/factory_secret').read().strip()
    if os.path.exists('/etc/jocotoco/factory_secret') else ''
)

# Codigos en los que reintentar no sirve de nada.
DEFINITIVOS = {400, 403, 404, 409}


def auto_register_device() -> bool:
    crt_path, key_path = config.CERT_FILES[0], config.CERT_FILES[1]
    if os.path.exists(crt_path) and os.path.exists(key_path):
        return True

    if not FACTORY_CLAIM_SECRET:
        logger.error('[STEP-CA] No hay secreto de fabrica configurado.')
        return False

    logger.info('[STEP-CA] Solicitando token de inscripcion...')
    try:
        response = requests.post(
            config.GET_TOKEN,                 # https://<api>:<puerto>/v1/inscripcion
            json={
                'device_id': config.DEVICE_ID,
                'factory_secret': FACTORY_CLAIM_SECRET,
            },
            verify=config.CA_CERT,            # raiz de step-ca
            timeout=15,
        )
    except requests.RequestException as e:
        logger.error(f'[STEP-CA] Sin respuesta del API: {e}')
        return False

    if response.status_code != 200:
        nivel = logger.error if response.status_code in DEFINITIVOS else logger.warning
        nivel(f'[STEP-CA] Inscripcion rechazada ({response.status_code}): {response.text}')
        return False

    datos = response.json()

    cmd = [
        'step', 'ca', 'certificate',
        config.DEVICE_ID,
        crt_path,
        key_path,
        '--token', datos['token'],
        '--force',
    ]

    # La respuesta trae a que CA dirigirse; asi no hace falta bootstrap previo.
    if datos.get('ca_url'):
        cmd += ['--ca-url', datos['ca_url']]
    if config.CA_CERT:
        cmd += ['--root', config.CA_CERT]

    try:
        subprocess.run(cmd, check=True, capture_output=True, text=True, timeout=60)
    except subprocess.CalledProcessError as e:
        logger.error(f'[STEP-CA] step ca certificate fallo: {e.stderr.strip()}')
        return False
    except subprocess.TimeoutExpired:
        logger.error('[STEP-CA] step ca certificate no respondio a tiempo.')
        return False

    os.chmod(key_path, 0o600)
    logger.info('[STEP-CA] Identidad mTLS obtenida.')
    return True
```

### Lo que falta despues de obtener el certificado

Conseguir la identidad es la mitad; hay que mantenerla y usarla:

1. **Renovacion.** El certificado caduca (24 h por defecto). Instale el
   temporizador de `deploy/raspberry/systemd/` o replique su efecto:

   ```bash
   step ca renew --force --expires-in 8h <crt> <key>
   ```

   Para equipos que pasan semanas sin conexion, amplie la vigencia en el
   servidor con `07-vigencia-dispositivos.sh` (ver
   [`RASPBERRY.md`](RASPBERRY.md), punto 7).

2. **Reloj.** Sin NTP ni RTC, el handshake TLS falla por fecha. Vea el
   punto 1 de `RASPBERRY.md`.

3. **Envio de audio.** Con el certificado ya puede subir:

   ```python
   requests.post(
       f'{config.API_URL}/v1/grabaciones',
       cert=(crt_path, key_path),
       verify=config.CA_CERT,
       headers={
           'Content-Type': 'audio/wav',
           'X-Audio-SHA256': sha256_del_archivo,
           'X-Grabado-En': '2026-09-23T05:30:00Z',
           'X-Metadatos': json.dumps({'sitio': 'Reserva Yanacocha'}),
       },
       data=open(ruta, 'rb'),
       timeout=180,
   )
   ```

   El contrato completo esta en [`API.md`](API.md). Si prefiere no escribir
   esa parte, `deploy/raspberry/enviar-audio.sh` ya la implementa con cola
   en disco y reintentos.

---

## El secreto compartido: limites de este esquema

Un secreto igual en todos los equipos significa que quien obtenga uno puede
inscribir dispositivos con cualquier nombre. Por eso la inscripcion viene
acotada:

- deshabilitada por defecto, y el secreto debe tener 16 caracteres o mas;
- `device_id` tiene que ser un hostname en minusculas, y puede exigirse que
  este en una lista blanca (`--dispositivos`);
- tope de tokens por dispositivo (2 por defecto: el segundo cubre una
  reinstalacion, no una fabrica de identidades);
- tope de intentos fallidos por IP (10 por hora);
- comparacion del secreto en tiempo constante (`hash_equals`);
- cada intento queda registrado con IP, resultado y motivo;
- un dispositivo se bloquea sin tocar la CA.

Si necesita algo mas fuerte que un secreto compartido, las alternativas
razonables son un secreto distinto por equipo entregado al aprovisionar, o
el provisioner ACME de step-ca con validacion `device-attest-01` si el
hardware lo permite.
