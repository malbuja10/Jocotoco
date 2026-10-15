# Probar el API desde un escritorio Ubuntu

Como ejercitar los cuatro endpoints del monitor —evento con audio, voltaje,
estado del dispositivo y audio suelto— desde una maquina que no es la
Raspberry. Todo lo protegido exige certificado de cliente, asi que el primer
paso es conseguir uno para el escritorio.

Use un certificado **propio** del escritorio, nunca el de una Raspberry en
produccion: el API registra lo que llega bajo la identidad del certificado y
mezclaria sus pruebas con los datos del campo.

## 1. La raiz de la CA

```bash
mkdir -p ~/.jocotoco && chmod 700 ~/.jocotoco
scp usuario@dev.minkafab.com:/etc/step/certs/root_ca.crt ~/.jocotoco/
step certificate fingerprint ~/.jocotoco/root_ca.crt   # opcional, para cotejar
```

La huella debe coincidir con la que anoto al instalar la CA.

## 2. Un certificado de cliente para el escritorio

**En el servidor**, genere el token y el par con la CA local:

```bash
cd /opt/src/jocotoco/deploy/scripts
sudo ./04-registrar-dispositivo.sh escritorio-pruebas --validez 60m
# copie el TOKEN que imprime
sudo STEPPATH=/root/.step step ca certificate escritorio-pruebas \
     /tmp/escritorio.crt /tmp/escritorio.key --token '<TOKEN>'
sudo chmod 644 /tmp/escritorio.crt /tmp/escritorio.key
```

**En el escritorio**, traigalos y protejalos:

```bash
scp usuario@dev.minkafab.com:/tmp/escritorio.crt ~/.jocotoco/cliente.crt
scp usuario@dev.minkafab.com:/tmp/escritorio.key ~/.jocotoco/cliente.key
chmod 600 ~/.jocotoco/cliente.key
```

Y borre las copias del servidor: `sudo rm /tmp/escritorio.crt /tmp/escritorio.key`.

> Si prefiere que la llave privada no salga nunca del escritorio, instale
> step-cli ahi y pida el certificado usted mismo con el token:
> `step ca bootstrap --ca-url https://ca.jocotoco.local:8443 --fingerprint <HUELLA>`
> y `step ca certificate escritorio-pruebas ~/.jocotoco/cliente.crt ~/.jocotoco/cliente.key --token '<TOKEN>'`.
> Requiere que el nombre de la CA resuelva desde el escritorio (ver
> `06-publicar-nombre-ca.sh`) y que el 8443 este alcanzable.

Compruebe que el certificado es el que cree:

```bash
openssl x509 -in ~/.jocotoco/cliente.crt -noout -subject -dates -ext subjectAltName
```

## 3. Probarlo todo de una vez

```bash
scp usuario@dev.minkafab.com:/opt/src/jocotoco/deploy/herramientas/jocotoco-probar ~/.local/bin/
chmod +x ~/.local/bin/jocotoco-probar
jocotoco-probar
```

Sin argumentos usa `https://dev.minkafab.com:8444` y los archivos de
`~/.jocotoco/`, genera un WAV de prueba de un segundo y recorre todo:

```
1/4  POST /api/sincronizar   (evento + WAV)
  ✔  evento nuevo                                   HTTP 201
        evento 01M50TBXF23XEVMK1S0606XZAP · grabacion 01M50TBXF0VA749HNH173VF5X5
  ✔  mismo envio otra vez (idempotencia)            HTTP 200
        Idempotent-Replay: true  (no se duplico)
  ✔  evento con device_id ajeno (debe fallar)       HTTP 403
  ✔  archivo que no es WAV (debe fallar)            HTTP 415
```

Ademas de los caminos felices comprueba los rechazos (403 con `device_id`
ajeno, 415 con un archivo que no es WAV, 422 con una metrica imposible), la
deduplicacion de telemetria y que el audio descargado es identico al subido.
Devuelve 0 si todo pasa, asi que sirve en un cron o en CI.

Opciones utiles:

```bash
jocotoco-probar --audio grabacion-real.wav   # en vez del WAV generado
jocotoco-probar --solo voltaje               # un solo endpoint
jocotoco-probar --verboso                    # cuerpos completos
jocotoco-probar --url https://otro-servidor:8444
```

## 4. Con una herramienta grafica

Postman, Insomnia y Bruno sirven, y en los tres el camino es el mismo:

1. Registrar el **certificado de cliente** para el host `dev.minkafab.com`
   (puerto 8444), indicando `cliente.crt` y `cliente.key`.
2. Añadir `root_ca.crt` como **CA de confianza**, o la peticion fallara con
   un error de certificado desconocido.
3. Importar [`openapi.yaml`](openapi.yaml), que ya trae las rutas, los
   esquemas y ejemplos de cada payload.

En Postman son dos pantallas distintas (el certificado de cliente en
*Settings → Certificates*; la CA en *Settings → General*), y conviene dejar
activada la verificacion TLS: si la apaga, deja de probar justamente lo que
mas importa aqui.

## 5. A mano, con curl

```bash
C="--cert $HOME/.jocotoco/cliente.crt --key $HOME/.jocotoco/cliente.key --cacert $HOME/.jocotoco/root_ca.crt"
API=https://dev.minkafab.com:8444

# Salud (no pide certificado) e identidad
curl -s --cacert ~/.jocotoco/root_ca.crt $API/v1/salud
curl -s $C $API/v1/yo

# 1. Evento de deteccion + WAV
curl -s $C \
  -F 'data={"event_id":"prueba-0001","threat_type":"chainsaw","confidence_score":0.87,"timestamp":'"$(date +%s)"'}' \
  -F 'audio=@grabacion.wav;type=audio/wav' \
  $API/api/sincronizar

# 2. Voltaje
curl -s $C -H 'Content-Type: application/json' \
  -d '{"voltage":[{"timestamp":'"$(date +%s)"',"value":12.4}]}' \
  $API/api/telemetry/voltage

# 3. Estado del dispositivo
curl -s $C -H 'Content-Type: application/json' \
  -d '{"device_telemetry":[{"timestamp":'"$(date +%s)"',"cpu_temp":48.2,"cpu_usage_pct":14.5,"ram_usage_pct":37.1,"disk_free_gb":21.8,"disk_usage_pct":32.0,"uptime_hours":76.4}]}' \
  $API/api/telemetry/status

# 4. Audio suelto
curl -s $C -H 'Content-Type: audio/wav' --data-binary @grabacion.wav $API/v1/grabaciones

# Y lo que quedo guardado
curl -s $C $API/v1/eventos
curl -s $C $API/v1/telemetria
```

## 6. Cuando algo falla

| Sintoma | Causa habitual |
|---------|----------------|
| `403` con `"Se requiere un certificado de cliente valido"` | falto `--cert`/`--key`, o la herramienta no esta mandando el certificado para ese host |
| `tlsv1 alert unknown ca` | su certificado de cliente no lo emitio esta CA |
| `certificate has expired` | el certificado del escritorio vencio; pida otro con `04-registrar-dispositivo.sh` |
| `self-signed certificate in certificate chain` | falta `--cacert ~/.jocotoco/root_ca.crt` |
| Se queda colgado y termina en *timed out* | el puerto 8444 no esta abierto desde esa red |
| `404` con `"La ruta ... no existe"` | las rutas del monitor son `/api/...`, sin `/v1` |
| `409` al inscribir un equipo | agoto sus tokens de la hora; `bin/jocotoco reiniciar-inscripcion <equipo>` |

Para ver el detalle desde el servidor mientras prueba:

```bash
sudo tail -f /var/log/jocotoco/api.log
sudo tail -f /var/log/apache2/jocotoco-api.error.log
```
