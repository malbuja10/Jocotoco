# Cliente en Python para la Raspberry Pi

`jocotoco_client.py` cubre el ciclo completo del dispositivo sin depender de
los scripts de bash de `deploy/raspberry/`:

1. **alta** contra `POST /v1/inscripcion` (token de step-ca y certificado),
2. **renovacion** del certificado antes de que caduque,
3. **envio** de grabaciones por mTLS, con cola en disco y reintentos.

Dependencias: `requests`, el binario `step` y `openssl`. Nada mas.

## Instalacion en el equipo

```bash
sudo install -d -m 0755 /opt/jocotoco
sudo install -m 0644 jocotoco_client.py /opt/jocotoco/
sudo install -d -m 0700 /etc/jocotoco/tls
sudo install -d -m 0755 /var/lib/jocotoco/cola /var/lib/jocotoco/enviadas

# Configuracion (el secreto de fabrica se puede borrar tras el alta)
sudo tee /etc/jocotoco/dispositivo.env >/dev/null <<'ENV'
JOCOTOCO_API_URL=https://dev.midominio.com:8444
JOCOTOCO_DISPOSITIVO=rpi-yanacocha-01
JOCOTOCO_CERT=/etc/jocotoco/tls/dispositivo.crt
JOCOTOCO_KEY=/etc/jocotoco/tls/dispositivo.key
JOCOTOCO_CA=/etc/jocotoco/tls/raiz-ca.crt
JOCOTOCO_COLA=/var/lib/jocotoco/cola
JOCOTOCO_ENVIADAS=/var/lib/jocotoco/enviadas
ENV

echo -n 'sk_factory_...' | sudo tee /etc/jocotoco/factory_secret >/dev/null
sudo chmod 0600 /etc/jocotoco/factory_secret

# La raiz de la CA (pidala al servidor o copiela por scp)
sudo cp raiz-ca.crt /etc/jocotoco/tls/raiz-ca.crt

# Temporizador: alta + renovacion + envio cada 10 minutos
sudo install -m 0644 systemd/jocotoco-cliente.* /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now jocotoco-cliente.timer
```

## Desde linea de comandos

```bash
python3 /opt/jocotoco/jocotoco_client.py alta       # da de alta el equipo
python3 /opt/jocotoco/jocotoco_client.py estado     # como lo ve el API
python3 /opt/jocotoco/jocotoco_client.py renovar    # renueva si hace falta
python3 /opt/jocotoco/jocotoco_client.py cola       # sube lo pendiente
python3 /opt/jocotoco/jocotoco_client.py subir grabacion.wav
```

## Desde su propio programa

```python
from jocotoco_client import ClienteJocotoco, ConfiguracionCliente, Resultado

cliente = ClienteJocotoco(ConfiguracionCliente.desde_entorno(
    metadatos_fijos={'sitio': 'Reserva Yanacocha', 'estacion': 'E-07'},
))

cliente.asegurar_identidad()      # no hace nada si ya tiene certificado
cliente.renovar_si_hace_falta()   # oportunista: sin red, no pasa nada

# Al terminar de grabar: encolar y dejar que el temporizador lo suba,
ruta_en_cola = cliente.encolar('/tmp/grabacion.wav')

# ...o subir en el momento y decidir segun el resultado:
resultado = cliente.subir('/tmp/grabacion.wav', metadatos={'nota': 'lluvia'})
if resultado is Resultado.RECHAZADO:
    ...   # definitivo: el API no lo va a aceptar nunca
elif resultado is Resultado.TEMPORAL:
    ...   # sin red o error del servidor: reintentar luego
```

`subir()` **no lanza excepciones**: devuelve `Resultado`, que es lo que hace
falta para decidir entre reintentar y descartar.

## Comprobado

El modulo se probo contra el API real (Apache 2.4.58 + php8.3-fpm, mTLS con
una CA de prueba): alta sin certificado previo, lectura de la vigencia,
renovacion, `/v1/yo`, subida con deteccion de duplicado, vaciado de cola,
audio invalido marcado `.rechazado` sin reintentos, y API inalcanzable
clasificado como temporal.
