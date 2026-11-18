# Panel web de monitoreo

Sitio web para supervisar lo que envían los dispositivos remotos al API. Se
usa desde el navegador, con usuario y contraseña, y lee la misma base
PostgreSQL que llena el API.

| Requisito | Dónde está en el panel |
|---|---|
| **a) Lista de dispositivos y su estado** | Página principal (`index.php`): tarjetas o tabla, estado *En línea / Inactivo / Desconectado / Sin datos*, último voltaje, corriente y potencia, temperatura de CPU, amenazas sin atender, alertas eléctricas, búsqueda y filtro. Se actualiza sola cada 30 s |
| **b) Voltaje, corriente y gráficas para análisis** | Ficha del dispositivo, pestaña *Voltaje, corriente y gráficas*: valores actuales; gráficas de voltaje (con límites y ▲ en cada amenaza), corriente, potencia (V × I), distribución del voltaje, temperatura de CPU y uso de CPU/RAM; mínimo, promedio, máximo y desviación estándar, lecturas fuera de rango y energía estimada (Wh); rangos de 1 h a 90 días o fechas a elección |
| **c) Amenazas, archivos para escuchar e históricos** | Pestaña *Amenazas y audios*: gráficas por día y por clase, tabla con reproductor de audio, descarga del WAV y “Marcar atendida” (queda registrado quién y cuándo). Pestaña *Históricos*: voltaje, corriente, estado del equipo o amenazas entre dos fechas, con resumen diario, páginas y exportación CSV. La página *Amenazas* reúne las detecciones de todos los equipos |

La pestaña *Ficha del equipo* permite dar a cada dispositivo un nombre visible,
una ubicación, coordenadas (con enlace al mapa) y notas.

## De dónde sale cada dato

| En el panel | Tabla | La llena |
|---|---|---|
| Dispositivos y última conexión | `devices` | el API, en cada petición autenticada con mTLS |
| Voltaje | `voltage_readings` | `POST /api/telemetry/voltage` |
| Corriente | `current_readings` | `POST /api/telemetry/current` |
| Temperatura, CPU, RAM, disco, encendido | `device_telemetry` | `POST /api/telemetry/status` |
| Amenazas | `detection_events` | `POST /api/sincronizar` |
| Audio de cada amenaza | `recordings` + `/var/lib/jocotoco/audio` | `POST /api/sincronizar` |
| Nombre, ubicación, notas | `device_profiles` | el panel |
| Revisión (“atendida”) | `event_reviews` | el panel |

Notas:

- **Corriente.** Es un endpoint nuevo. Mientras el equipo de campo no lo use,
  el panel muestra “—” en corriente y en potencia; el voltaje, el estado y las
  amenazas se ven igual. Formato en [`API.md`](API.md#post-apitelemetrycurrent).
- **Clase de la amenaza.** Es la etiqueta que asigna el modelo del equipo, no
  una amenaza confirmada: por eso el panel ofrece el audio al lado. El nivel
  (Alta/Media/Baja) se deriva de la confianza del modelo, con los umbrales de
  más abajo.
- **Horas.** Las marcas del dispositivo (epoch UTC) se muestran en la zona del
  panel (`America/Guayaquil` por defecto). “Detectado” es la hora del equipo y
  “recibido” la del servidor: si difieren mucho, el reloj del equipo está
  corrido o estuvo sin enlace.

## Instalación

Requiere el API ya instalado (pasos 02 y 03 de [`DESPLIEGUE.md`](DESPLIEGUE.md)).

```bash
cd /ruta/al/repositorio
sudo deploy/scripts/02-instalar-api.sh --dominio dev.minkafab.com --puerto 8444   # actualiza /srv/jocotoco
sudo deploy/scripts/09-instalar-panel.sh --dominio dev.minkafab.com --puerto 8445 --usuario admin
```

`09-instalar-panel.sh`:

1. crea el usuario del sistema `jocotoco_panel` y lo agrega al grupo
   `jocotoco`, para que pueda leer los audios (0640);
2. crea el rol de PostgreSQL `jocotoco_panel`: puede **leer** todas las tablas
   y **escribir solo** `device_profiles` y `event_reviews`. No puede borrar ni
   alterar lo que recibió el API;
3. instala un pool de php-fpm propio (`/etc/php/8.3/fpm/pool.d/jocotoco-panel.conf`);
4. pide la contraseña del primer usuario (`/etc/jocotoco/panel.htpasswd`, bcrypt);
5. publica el sitio en `https://<dominio>:<puerto>/` con el certificado de
   Let's Encrypt del dominio si existe, o el que se indique con
   `--cert`/`--key`. Si no hay ninguno usa el del API (step-ca); el navegador
   lo marcará como no confiable salvo que se instale la raíz de la CA.

Abre el puerto en `ufw` si está activo. Puede ejecutarse otra vez para
actualizar: conserva usuarios y contraseñas.

### Usuarios

```bash
sudo htpasswd -B /etc/jocotoco/panel.htpasswd maria     # agregar o cambiar contraseña
sudo htpasswd -D /etc/jocotoco/panel.htpasswd maria     # quitar
```

El nombre de usuario queda guardado en cada revisión de amenaza.

### Ajustes (`/etc/php/8.3/fpm/pool.d/jocotoco-panel.conf`)

| Variable | Defecto | Para qué |
|---|---|---|
| `JOCOTOCO_PANEL_ONLINE_SECONDS` | `1800` | Hasta 30 min sin conexión: *En línea* |
| `JOCOTOCO_PANEL_IDLE_SECONDS` | `21600` | Hasta 6 h: *Inactivo*; más: *Desconectado* |
| `JOCOTOCO_PANEL_VOLTAGE_MIN` / `_MAX` | `11.5` / `14.8` | Rango normal de voltaje (V) |
| `JOCOTOCO_PANEL_CURRENT_MAX` | `5.0` | Corriente máxima (A) |
| `JOCOTOCO_PANEL_CONFIDENCE_HIGH` / `_MEDIUM` | `0.85` / `0.6` | Confianza para nivel Alta / Media |
| `JOCOTOCO_PANEL_TIMEZONE` | `America/Guayaquil` | Zona en que se muestran las horas |

Después de cambiarlos: `sudo systemctl reload php8.3-fpm`.

## Seguridad

- Sitio y puerto propios, separados del API: el API exige certificado de
  cliente, el panel exige usuario y contraseña (Apache, `AuthType Basic`
  sobre TLS).
- Proceso, usuario del sistema y rol de base de datos distintos de los del
  API, con permisos mínimos (ver arriba).
- Los audios no están en el DocumentRoot: los sirve `audio.php`, que busca la
  grabación por su identificador y comprueba que la ruta quede dentro de
  `/var/lib/jocotoco/audio`.
- Los cambios (marcar atendida, editar la ficha) exigen un token CSRF de la
  sesión; las páginas envían `Content-Security-Policy` sin scripts en línea.
- Si el panel solo se usa desde la oficina, conviene limitar además por IP
  (bloque `RequireAll` comentado en `deploy/apache/jocotoco-panel.conf`).

## Probarlo en un equipo de desarrollo

```bash
composer install
export JOCOTOCO_DATA_DIR=/tmp/jocotoco
export JOCOTOCO_DB_DSN="pgsql:host=127.0.0.1;port=5432;dbname=jocotoco"
export JOCOTOCO_DB_USER=jocotoco JOCOTOCO_DB_PASSWORD=...
php bin/jocotoco migrate
php panel/herramientas/datos-demo.php --confirmar    # 5 equipos demo-*, 7 días, audios WAV
php -S 127.0.0.1:8090 -t panel/public                # http://127.0.0.1:8090
php panel/herramientas/datos-demo.php --borrar       # quita los demo-*
```

`datos-demo.php` solo toca filas y archivos de equipos cuyo nombre empieza con
`demo-`; aun así, no lo ejecute en el servidor de producción.

## Estructura

```
panel/
├── bootstrap.php            configuración y conexión (reutiliza config/ y src/ del API)
├── lib/consultas.php        consultas (SQL común a PostgreSQL y SQLite)
├── lib/vista.php            utilidades de presentación, CSRF, diseño
├── lib/tabla_eventos.php    tabla de detecciones con audio
├── herramientas/datos-demo.php
└── public/                  DocumentRoot
    ├── index.php            lista de dispositivos
    ├── dispositivo.php      gráficas, amenazas, históricos, ficha
    ├── amenazas.php         detecciones de todos los equipos
    ├── audio.php            audio con soporte de rangos (adelantar/retroceder)
    ├── exportar.php         CSV
    ├── api/datos.php        JSON para las gráficas y la lista
    ├── api/accion.php       marcar atendida
    └── assets/              CSS, JS y Chart.js 4.4.1 local (funciona sin internet)
```
