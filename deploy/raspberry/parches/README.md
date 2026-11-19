# Parches para el software del monitor

Cambios preparados para el repositorio del equipo de campo
(`malbuja10/monitor-threat-system`), que vive aparte de este. Se entregan
como parche para que su autor los revise y los aplique: desde aqui no se
toca ese repositorio.

## monitor-renovar-cada-30-dias.patch

Hace que el certificado mTLS del dispositivo se renueve **cada 30 dias**,
igual que el del servidor.

**Que problema resuelve.** `check_and_renew_certificate()` llamaba a
`step ca renew --force` sin `--expires-in`, y `--force` no significa "forzar
si toca" sino "no preguntes": el certificado se reemplazaba en **cada**
sincronizacion. Con enlaces por antena eso es una vuelta a la CA y una
clave nueva cada vez que el equipo se conecta, sin ninguna ganancia.

**Como queda.** `step ca renew` no renueva por calendario sino por lo que le
quede de vida al certificado, asi que renovar cada 30 dias es pedir el
umbral "vigencia menos 30 dias": con los certificados de 90 dias que emite
la CA, `1440h`. La funcion se puede seguir llamando en cada ventana de
conexion —de hecho conviene— porque esas llamadas de mas no renuevan nada
mientras no toque, y son las que permiten que un equipo que estuvo semanas
sin enlace se ponga al dia en su primera conexion.

De paso, tres cosas que se veian en ese bloque:

- se anade `timeout=120` a `subprocess.run`: sin el, una CA que acepta la
  conexion y no responde cuelga el hilo de sincronizacion para siempre;
- se capturan `FileNotFoundError` (step no instalado) y `TimeoutExpired`,
  que antes subian hasta el `except Exception` del bucle y se confundian
  con un fallo de envio;
- el log dice ahora si **de verdad** renovo, comparando la fecha del
  archivo antes y despues. Importa porque con el umbral de 30 dias la
  mayoria de las ejecuciones no haran nada, y el mensaje anterior
  ("verified and updated successfully") habria dicho lo contrario cada vez.

**Base.** Commit `4557336` de `origin/main`. Comprobado con `git apply
--check` sobre ese commit.

**Aplicar:**

```bash
cd monitor-threat-system
git checkout -b renovacion-30-dias
git apply --check /ruta/al/monitor-renovar-cada-30-dias.patch   # verifica
git apply          /ruta/al/monitor-renovar-cada-30-dias.patch
python3 -m py_compile monitor/config.py monitor/network.py
git commit -am "Renovar el certificado del dispositivo cada 30 dias"
```

**Ajustar el ritmo.** El valor por defecto son 30 dias sobre certificados de
90 (`1440h`). Para otro ritmo, sin tocar codigo: la variable de entorno
`CERT_RENEW_EXPIRES_IN` (en `docker-compose.yml`), o la clave
`cert_renew_expires_in` en el archivo de configuracion remota del equipo,
que `config.py` consulta antes que el entorno. El valor se valida al
arrancar: si no es una duracion que entienda step (`1440h`, `36h30m`), el
servicio no arranca en lugar de renovar mal en silencio.

Si la CA cambia la vigencia de los certificados, este umbral hay que
recalcularlo: es "vigencia menos 30 dias". En el servidor lo hace solo
`deploy/scripts/03-emitir-cert-servidor.sh`, leyendo el certificado
emitido.

**Comprobado** con un `step` falso en el PATH, los cinco caminos:
renovacion efectiva (reescribe el archivo), certificado todavia vigente (no
lo toca), salida distinta de cero, binario ausente y proceso colgado. En
todos el valor devuelto y el mensaje de log son los correctos, y el comando
que se construye es:

```
step ca renew --force --expires-in 1440h --ca-url <CA> --root <raiz> <crt> <key>
```
