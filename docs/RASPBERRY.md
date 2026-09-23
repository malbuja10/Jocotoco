# Configuracion de una Raspberry Pi

Probado en Raspberry Pi OS Bookworm de 64 bits (arm64) y en Ubuntu Server
24.04 para arm64. El dispositivo solo necesita `step-cli`, `curl` y, si va a
grabar, `alsa-utils`.

## 1. Antes de empezar: el reloj

Un reloj desfasado invalida el certificado y produce errores `403` confusos.
La Raspberry Pi no tiene reloj con bateria, asi que:

```bash
sudo timedatectl set-ntp true
timedatectl status          # NTP service: active, System clock synchronized: yes
```

## 2. La CA tiene que ser alcanzable por nombre

step-ca solo atiende peticiones cuyo nombre figure en `dnsNames` de
`ca.json`, y `step-cli` valida su certificado TLS contra ese nombre. Si la
CA se inicializo con un nombre interno (`ca.jocotoco.local`), los
dispositivos no podran renovar su certificado y a las 24 h dejaran de subir
audio.

Para publicar un nombre que los dispositivos si resuelvan:

```bash
sudo ./06-publicar-nombre-ca.sh ca.midominio.com
```

El script agrega el nombre (no reemplaza el existente), respalda `ca.json`,
reinicia step-ca para que regenere su certificado TLS y, si no vuelve a
responder, **restaura el respaldo**. Compruebe tambien que el puerto 8443
sea alcanzable desde los dispositivos (cortafuegos, NAT).

## 3. Obtener el token en el servidor

```bash
sudo deploy/scripts/04-registrar-dispositivo.sh rpi-yanacocha-01
```

El nombre que elija (`rpi-yanacocha-01`) queda como CN y SAN DNS del
certificado y es la identidad con la que el API guarda las grabaciones:
aparece en las rutas de los archivos y en los listados. Use nombres de
hostname (minusculas, digitos, guiones y puntos).

## 4. Bootstrap del dispositivo

Copie `deploy/raspberry/` a la Raspberry y ejecute, antes de que el token
venza (5 minutos):

```bash
sudo ./bootstrap-dispositivo.sh \
     --nombre rpi-yanacocha-01 \
     --ca-url https://ca.jocotoco.local:8443 \
     --huella <HUELLA_DE_LA_RAIZ> \
     --token '<TOKEN>' \
     --api-url https://api.jocotoco.local
```

Esto:

1. instala `step-cli` (paquete `.deb` arm64);
2. `step ca bootstrap` con la huella de la raiz: asi el dispositivo confia en
   la CA sin depender del almacen de certificados del sistema;
3. `step ca certificate` con el token: obtiene
   `/etc/jocotoco/tls/dispositivo.{crt,key}` (llave en modo 0600);
4. escribe `/etc/jocotoco/dispositivo.env`;
5. instala `jocotoco-enviar-audio` y `jocotoco-grabar-audio` en
   `/usr/local/bin` y activa dos temporizadores;
6. consulta `/v1/yo` para confirmar que el API ya lo reconoce.

## 5. Grabar y enviar

```bash
# Grabar 60 s y dejar en la cola (el temporizador la vacia cada 10 min)
jocotoco-grabar-audio --segundos 60

# Grabar y enviar de inmediato
jocotoco-grabar-audio --segundos 60 --enviar

# Enviar un archivo con metadatos
jocotoco-enviar-audio /home/pi/grabacion.wav \
    --sitio "Reserva Yanacocha" --estacion E-07 --nota "lluvia ligera"

# Vaciar la cola ahora
jocotoco-enviar-audio --cola
```

Microfono: liste los dispositivos con `arecord -l` y pase el suyo con
`--dispositivo-alsa plughw:1,0` (o fije `JOCOTOCO_ALSA` en
`/etc/jocotoco/dispositivo.env`).

### La cola

`/var/lib/jocotoco/cola` guarda lo pendiente de envio. `grabar-audio.sh`
escribe primero un `.parcial` y renombra al terminar, de modo que el envio
nunca toma una grabacion a medias.

| Situacion | Que pasa con el archivo |
|-----------|-------------------------|
| Enviado (`201`) o duplicado (`200`) | Se mueve a `/var/lib/jocotoco/enviadas` |
| Rechazo definitivo (`400`, `413`, `415`, `422`, `401`, `403`) | Se renombra a `*.rechazado` para revision manual |
| Fallo de red o `5xx` | Se queda en la cola; el temporizador reintenta |

Codigos de salida de `jocotoco-enviar-audio`: `0` enviado, `1` rechazo
definitivo, `2` fallo temporal. La unidad systemd declara
`SuccessExitStatus=0 2` para que un corte de red no la deje en estado fallido.

## 6. Grabacion programada

Para grabar cada hora, agregue un temporizador propio:

```ini
# /etc/systemd/system/jocotoco-grabar.service
[Unit]
Description=Grabar audio y encolar para envio

[Service]
Type=oneshot
ExecStart=/usr/local/bin/jocotoco-grabar-audio --segundos 300
```

```ini
# /etc/systemd/system/jocotoco-grabar.timer
[Unit]
Description=Grabacion horaria

[Timer]
OnCalendar=hourly
Persistent=true

[Install]
WantedBy=timers.target
```

```bash
sudo systemctl daemon-reload && sudo systemctl enable --now jocotoco-grabar.timer
```

Vigile el espacio en disco: 5 minutos de WAV 48 kHz 16 bit mono son unos
28 MB. `arecord` solo escribe WAV, asi que para grabaciones largas conviene
comprimir antes de encolar (`flac --best grabacion.wav` reduce a la mitad sin
perdida; el API acepta FLAC) o acortar el intervalo del envio.

## 7. Renovacion del certificado

`jocotoco-renovar-cert.timer` corre cada 4 h y ejecuta
`step ca renew --expires-in 8h`: renueva solo si al certificado le quedan
menos de 8 horas.

```bash
systemctl list-timers 'jocotoco-*'
sudo systemctl start jocotoco-renovar-cert.service     # renovar ahora
step certificate inspect /etc/jocotoco/tls/dispositivo.crt --short
```

Si el certificado caduco estando el equipo apagado varios dias, la renovacion
ya no es posible (step-ca exige un certificado vigente): pida un token nuevo y
vuelva a ejecutar `bootstrap-dispositivo.sh`.

## 8. Diagnostico

```bash
# Como me ve el API
curl -s --cert /etc/jocotoco/tls/dispositivo.crt \
        --key  /etc/jocotoco/tls/dispositivo.key \
        --cacert /etc/jocotoco/tls/raiz-ca.crt \
        https://api.jocotoco.local/v1/yo | jq

# Limites vigentes en el servidor
curl -s --cert ... --key ... --cacert ... https://api.jocotoco.local/v1/limites | jq

# Diagnostico del handshake TLS
step certificate verify /etc/jocotoco/tls/dispositivo.crt \
     --roots /etc/jocotoco/tls/raiz-ca.crt

# Registro de los envios (el script escribe en journald con tag jocotoco-enviar)
journalctl -t jocotoco-enviar -n 50
journalctl -u jocotoco-enviar-cola.service -n 50
```

| Sintoma | Que revisar |
|---------|-------------|
| `curl: (58)` o `(35)` | Permisos o rutas de `dispositivo.crt`/`.key` |
| `401`/`403` del API | Vigencia del certificado y lista blanca del servidor |
| `403` "aun no es valido" | Reloj sin sincronizar (`timedatectl set-ntp true`) |
| Todo se queda en la cola | DNS o firewall hacia `api.jocotoco.local:443` |
| `415` | El archivo no es audio valido; revise la grabacion de `arecord` |
