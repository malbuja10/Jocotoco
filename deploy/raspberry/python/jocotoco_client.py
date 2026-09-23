"""Cliente del API de audio Jocotoco para la Raspberry Pi.

Cubre el ciclo completo de vida del dispositivo, para integrarlo en un
programa propio en Python sin depender de los scripts de bash:

    1. alta automatica contra POST /v1/inscripcion (obtiene un token de
       step-ca y con el su certificado),
    2. renovacion del certificado antes de que caduque,
    3. envio de grabaciones por mTLS, con cola en disco y reintentos.

Dependencias: requests y el binario ``step``. Sin librerias de criptografia:
la lectura de fechas del certificado se delega a ``step`` u ``openssl``.

Uso tipico dentro de un programa:

    from jocotoco_client import ClienteJocotoco, ConfiguracionCliente

    cliente = ClienteJocotoco(ConfiguracionCliente(
        api_url='https://dev.midominio.com:8444',
        device_id='rpi-yanacocha-01',
        cert='/etc/jocotoco/tls/dispositivo.crt',
        key='/etc/jocotoco/tls/dispositivo.key',
        ca_cert='/etc/jocotoco/tls/raiz-ca.crt',
    ))

    cliente.asegurar_identidad()        # alta o nada, si ya tiene certificado
    cliente.renovar_si_hace_falta()     # oportunista: si no hay red, no pasa nada
    cliente.procesar_cola()             # sube lo pendiente

Tambien funciona como script, para un temporizador de systemd:

    python3 jocotoco_client.py cola
    python3 jocotoco_client.py renovar
    python3 jocotoco_client.py estado
"""

from __future__ import annotations

import hashlib
import json
import logging
import os
import shutil
import subprocess
import sys
import time
from dataclasses import dataclass, field
from datetime import datetime, timezone
from enum import Enum
from pathlib import Path

import requests

logger = logging.getLogger(__name__)

# Codigos con los que reintentar no sirve: el API rechazo el contenido o la
# identidad, y el resultado sera el mismo la proxima vez.
HTTP_DEFINITIVOS = frozenset({400, 401, 403, 404, 409, 413, 415, 422})

TIPOS_MEDIO = {
    '.wav': 'audio/wav',
    '.flac': 'audio/flac',
    '.ogg': 'audio/ogg',
    '.opus': 'audio/opus',
    '.mp3': 'audio/mpeg',
}


class Resultado(Enum):
    """Desenlace de un envio, que decide si se reintenta o no."""

    ENVIADO = 'enviado'
    DUPLICADO = 'duplicado'
    RECHAZADO = 'rechazado'      # definitivo: no reintentar
    TEMPORAL = 'temporal'        # sin red, o error del servidor: reintentar


@dataclass
class ConfiguracionCliente:
    api_url: str
    device_id: str
    cert: str = '/etc/jocotoco/tls/dispositivo.crt'
    key: str = '/etc/jocotoco/tls/dispositivo.key'
    ca_cert: str = '/etc/jocotoco/tls/raiz-ca.crt'
    cola: str = '/var/lib/jocotoco/cola'
    enviadas: str = '/var/lib/jocotoco/enviadas'
    # Solo hace falta para el alta; despues se puede borrar del equipo.
    factory_secret: str = ''
    ca_url: str = ''
    tiempo_limite_envio: int = 180
    tiempo_limite_control: int = 20
    reintentos: int = 3
    metadatos_fijos: dict = field(default_factory=dict)

    @classmethod
    def desde_entorno(cls, **extra) -> 'ConfiguracionCliente':
        """Lee JOCOTOCO_* del entorno y de /etc/jocotoco/dispositivo.env."""
        valores = {}
        env_file = Path('/etc/jocotoco/dispositivo.env')
        if env_file.is_file():
            for linea in env_file.read_text().splitlines():
                if linea.strip().startswith('#') or '=' not in linea:
                    continue
                clave, _, valor = linea.partition('=')
                valores[clave.strip()] = valor.strip().strip('"\'')
        valores.update({k: v for k, v in os.environ.items() if k.startswith('JOCOTOCO_')})

        secreto = valores.get('JOCOTOCO_FACTORY_SECRET', '')
        archivo_secreto = Path('/etc/jocotoco/factory_secret')
        if not secreto and archivo_secreto.is_file():
            secreto = archivo_secreto.read_text().strip()

        parametros = {
            'api_url': valores.get('JOCOTOCO_API_URL', 'https://localhost'),
            'device_id': valores.get('JOCOTOCO_DISPOSITIVO', os.uname().nodename),
            'cert': valores.get('JOCOTOCO_CERT', cls.cert),
            'key': valores.get('JOCOTOCO_KEY', cls.key),
            'ca_cert': valores.get('JOCOTOCO_CA', cls.ca_cert),
            'cola': valores.get('JOCOTOCO_COLA', cls.cola),
            'enviadas': valores.get('JOCOTOCO_ENVIADAS', cls.enviadas),
            'factory_secret': secreto,
            'ca_url': valores.get('JOCOTOCO_CA_URL', ''),
        }
        parametros.update(extra)

        return cls(**parametros)


class ClienteJocotoco:
    def __init__(self, config: ConfiguracionCliente) -> None:
        self.config = config

    # ------------------------------------------------------------------
    # 1. Identidad
    # ------------------------------------------------------------------

    def tiene_certificado(self) -> bool:
        return Path(self.config.cert).is_file() and Path(self.config.key).is_file()

    def asegurar_identidad(self) -> bool:
        """Da de alta el dispositivo si todavia no tiene certificado."""
        if self.tiene_certificado():
            return True

        if not self.config.factory_secret:
            logger.error('[alta] No hay secreto de fabrica configurado.')
            return False

        self.avisar_si_el_reloj_esta_mal()

        logger.info('[alta] Solicitando token de inscripcion al API...')
        try:
            respuesta = requests.post(
                f'{self.config.api_url}/v1/inscripcion',
                json={
                    'device_id': self.config.device_id,
                    'factory_secret': self.config.factory_secret,
                },
                verify=self.config.ca_cert,
                timeout=self.config.tiempo_limite_control,
            )
        except requests.RequestException as e:
            logger.error('[alta] Sin respuesta del API: %s', e)
            return False

        if respuesta.status_code != 200:
            registrar = logger.error if respuesta.status_code in HTTP_DEFINITIVOS else logger.warning
            registrar('[alta] Rechazada (%s): %s', respuesta.status_code, respuesta.text.strip())
            return False

        datos = respuesta.json()
        ca_url = datos.get('ca_url') or self.config.ca_url

        Path(self.config.cert).parent.mkdir(parents=True, exist_ok=True)

        orden = [
            'step', 'ca', 'certificate',
            self.config.device_id,
            self.config.cert,
            self.config.key,
            '--token', datos['token'],
            '--force',
        ]
        # Con --ca-url y --root no hace falta un "step ca bootstrap" previo.
        if ca_url:
            orden += ['--ca-url', ca_url]
        if Path(self.config.ca_cert).is_file():
            orden += ['--root', self.config.ca_cert]

        if not self._ejecutar(orden, '[alta] step ca certificate'):
            return False

        os.chmod(self.config.key, 0o600)
        logger.info('[alta] Identidad mTLS obtenida para %s.', self.config.device_id)

        return True

    # ------------------------------------------------------------------
    # 2. Renovacion
    # ------------------------------------------------------------------

    def vigencia(self) -> tuple[datetime, datetime] | None:
        """Fechas de validez del certificado, o None si no se pueden leer."""
        if not self.tiene_certificado():
            return None

        try:
            salida = subprocess.run(
                ['openssl', 'x509', '-in', self.config.cert, '-noout', '-startdate', '-enddate'],
                capture_output=True, text=True, check=True, timeout=10,
            ).stdout
        except (subprocess.SubprocessError, OSError) as e:
            logger.warning('[renovacion] No se pudo leer el certificado: %s', e)
            return None

        fechas = {}
        for linea in salida.splitlines():
            clave, _, valor = linea.partition('=')
            if clave in ('notBefore', 'notAfter'):
                fechas[clave] = datetime.strptime(valor.strip(), '%b %d %H:%M:%S %Y %Z') \
                    .replace(tzinfo=timezone.utc)

        if 'notBefore' not in fechas or 'notAfter' not in fechas:
            return None

        return fechas['notBefore'], fechas['notAfter']

    def segundos_para_caducar(self) -> int | None:
        rango = self.vigencia()
        if rango is None:
            return None

        return int((rango[1] - datetime.now(timezone.utc)).total_seconds())

    def renovar_si_hace_falta(self, umbral_segundos: int | None = None) -> bool:
        """Renueva cuando queda menos de un tercio de la vida del certificado.

        Es deliberadamente tolerante: en campo lo normal es no tener red, y
        un fallo aqui no debe interrumpir la grabacion ni el programa.
        """
        rango = self.vigencia()
        if rango is None:
            return False

        inicio, fin = rango
        if umbral_segundos is None:
            umbral_segundos = max(8 * 3600, int((fin - inicio).total_seconds() / 3))

        restantes = int((fin - datetime.now(timezone.utc)).total_seconds())
        if restantes > umbral_segundos:
            logger.debug('[renovacion] Sin urgencia: quedan %d h.', restantes // 3600)
            return False

        if restantes <= 0:
            logger.warning('[renovacion] El certificado ya caduco; se intenta de todos modos.')

        self.avisar_si_el_reloj_esta_mal()
        logger.info('[renovacion] Quedan %d h; renovando.', max(0, restantes) // 3600)

        orden = ['step', 'ca', 'renew', '--force', self.config.cert, self.config.key]
        if Path(self.config.ca_cert).is_file():
            orden += ['--root', self.config.ca_cert]
        if self.config.ca_url:
            orden += ['--ca-url', self.config.ca_url]

        if self._ejecutar(orden, '[renovacion] step ca renew'):
            logger.info('[renovacion] Certificado renovado.')
            return True

        return False

    # ------------------------------------------------------------------
    # 3. Envio de grabaciones
    # ------------------------------------------------------------------

    def subir(self, ruta: str | Path, metadatos: dict | None = None,
              grabado_en: str | None = None) -> Resultado:
        """Sube un archivo de audio. No lanza excepciones: devuelve Resultado."""
        ruta = Path(ruta)
        if not ruta.is_file():
            logger.error('[envio] No existe %s', ruta)
            return Resultado.RECHAZADO

        if not self.tiene_certificado():
            logger.error('[envio] Sin certificado; ejecute asegurar_identidad() primero.')
            return Resultado.TEMPORAL

        datos_extra = dict(self.config.metadatos_fijos)
        datos_extra.update(metadatos or {})

        cabeceras = {
            'Content-Type': TIPOS_MEDIO.get(ruta.suffix.lower(), 'application/octet-stream'),
            'X-Audio-SHA256': self.sha256(ruta),
            'X-Nombre-Archivo': ruta.name,
            'X-Grabado-En': grabado_en or self._fecha_de_modificacion(ruta),
            'Expect': '',
        }
        if datos_extra:
            cabeceras['X-Metadatos'] = json.dumps(datos_extra, ensure_ascii=False)

        espera = 5
        for intento in range(1, self.config.reintentos + 1):
            try:
                with ruta.open('rb') as audio:
                    respuesta = requests.post(
                        f'{self.config.api_url}/v1/grabaciones',
                        data=audio,
                        headers=cabeceras,
                        cert=(self.config.cert, self.config.key),
                        verify=self.config.ca_cert,
                        timeout=self.config.tiempo_limite_envio,
                    )
            except requests.RequestException as e:
                logger.warning('[envio] Intento %d/%d sin conexion: %s',
                               intento, self.config.reintentos, e)
                if intento < self.config.reintentos:
                    time.sleep(espera)
                    espera *= 2
                continue

            if respuesta.status_code in (200, 201):
                cuerpo = self._json(respuesta)
                identificador = (cuerpo.get('grabacion') or {}).get('id', 'sin id')
                duplicado = bool(cuerpo.get('duplicado'))
                logger.info('[envio] %s %s (%s)', ruta.name,
                            'ya estaba en el servidor' if duplicado else 'almacenada',
                            identificador)
                return Resultado.DUPLICADO if duplicado else Resultado.ENVIADO

            if respuesta.status_code in HTTP_DEFINITIVOS:
                detalle = self._json(respuesta).get('detail', respuesta.text.strip())
                logger.error('[envio] %s rechazada (%s): %s',
                             ruta.name, respuesta.status_code, detalle)
                if respuesta.status_code in (401, 403):
                    logger.error('[envio] Revise la vigencia del certificado y la lista blanca del API.')
                return Resultado.RECHAZADO

            logger.warning('[envio] Intento %d/%d devolvio %s',
                           intento, self.config.reintentos, respuesta.status_code)
            if intento < self.config.reintentos:
                time.sleep(espera)
                espera *= 2

        return Resultado.TEMPORAL

    def encolar(self, ruta: str | Path) -> Path:
        """Mueve un archivo a la cola de envio de forma atomica."""
        origen = Path(ruta)
        destino = Path(self.config.cola) / origen.name
        destino.parent.mkdir(parents=True, exist_ok=True)
        parcial = destino.with_suffix(destino.suffix + '.parcial')
        shutil.move(str(origen), str(parcial))
        parcial.rename(destino)

        return destino

    def procesar_cola(self) -> dict[str, int]:
        """Sube todo lo pendiente. Devuelve el recuento por resultado."""
        cola = Path(self.config.cola)
        if not cola.is_dir():
            return {}

        recuento: dict[str, int] = {}
        for archivo in sorted(cola.iterdir()):
            # Lo que aun se esta grabando lleva sufijo .parcial.
            if not archivo.is_file() or archivo.suffix == '.parcial' or archivo.name.endswith('.rechazado'):
                continue
            if archivo.suffix.lower() not in TIPOS_MEDIO:
                continue

            resultado = self.subir(archivo)
            recuento[resultado.value] = recuento.get(resultado.value, 0) + 1

            if resultado in (Resultado.ENVIADO, Resultado.DUPLICADO):
                enviadas = Path(self.config.enviadas)
                if enviadas.is_dir() or not enviadas.exists():
                    enviadas.mkdir(parents=True, exist_ok=True)
                    shutil.move(str(archivo), str(enviadas / archivo.name))
                else:
                    archivo.unlink()
            elif resultado is Resultado.RECHAZADO:
                # Se conserva para revision manual, fuera del ciclo de envio.
                archivo.rename(archivo.with_suffix(archivo.suffix + '.rechazado'))

        if recuento:
            logger.info('[cola] %s', ', '.join(f'{k}={v}' for k, v in sorted(recuento.items())))

        return recuento

    # ------------------------------------------------------------------
    # Utilidades
    # ------------------------------------------------------------------

    def estado(self) -> dict:
        """Como ve el API a este dispositivo (requiere certificado)."""
        try:
            respuesta = requests.get(
                f'{self.config.api_url}/v1/yo',
                cert=(self.config.cert, self.config.key),
                verify=self.config.ca_cert,
                timeout=self.config.tiempo_limite_control,
            )
        except requests.RequestException as e:
            return {'error': str(e)}

        if respuesta.status_code != 200:
            return {'error': f'HTTP {respuesta.status_code}', 'detalle': respuesta.text.strip()}

        return self._json(respuesta)

    def avisar_si_el_reloj_esta_mal(self) -> bool:
        """Un reloj desfasado hace fallar el TLS con un error poco claro.

        La Raspberry Pi no tiene reloj con bateria: sin NTP ni RTC puede
        arrancar con una fecha de hace anos.
        """
        ahora = datetime.now(timezone.utc)
        if ahora.year < 2024:
            logger.error('[reloj] La fecha del sistema es %s: el TLS va a fallar. '
                         'Sincronice con "timedatectl set-ntp true" o instale un RTC.',
                         ahora.date())
            return False

        try:
            salida = subprocess.run(
                ['timedatectl', 'show', '--property=NTPSynchronized', '--value'],
                capture_output=True, text=True, timeout=5,
            ).stdout.strip()
            if salida == 'no':
                logger.warning('[reloj] El reloj no esta sincronizado por NTP.')
        except (subprocess.SubprocessError, OSError):
            pass

        return True

    @staticmethod
    def sha256(ruta: str | Path) -> str:
        digestor = hashlib.sha256()
        with Path(ruta).open('rb') as archivo:
            for bloque in iter(lambda: archivo.read(262144), b''):
                digestor.update(bloque)

        return digestor.hexdigest()

    @staticmethod
    def _fecha_de_modificacion(ruta: Path) -> str:
        marca = datetime.fromtimestamp(ruta.stat().st_mtime, tz=timezone.utc)

        return marca.strftime('%Y-%m-%dT%H:%M:%SZ')

    @staticmethod
    def _json(respuesta: requests.Response) -> dict:
        try:
            datos = respuesta.json()
        except ValueError:
            return {}

        return datos if isinstance(datos, dict) else {}

    def _ejecutar(self, orden: list[str], etiqueta: str) -> bool:
        try:
            subprocess.run(orden, check=True, capture_output=True, text=True, timeout=120)
            return True
        except FileNotFoundError:
            logger.error('%s: falta el binario "%s".', etiqueta, orden[0])
        except subprocess.TimeoutExpired:
            logger.error('%s: no respondio a tiempo.', etiqueta)
        except subprocess.CalledProcessError as e:
            logger.error('%s fallo: %s', etiqueta, (e.stderr or '').strip() or e)

        return False


def main(argv: list[str]) -> int:
    logging.basicConfig(
        level=logging.INFO,
        format='%(asctime)s %(levelname)s %(message)s',
    )

    orden = argv[1] if len(argv) > 1 else 'ayuda'
    cliente = ClienteJocotoco(ConfiguracionCliente.desde_entorno())

    if orden == 'alta':
        return 0 if cliente.asegurar_identidad() else 1

    if orden == 'renovar':
        cliente.renovar_si_hace_falta()
        return 0

    if orden == 'cola':
        cliente.asegurar_identidad()
        cliente.renovar_si_hace_falta()
        recuento = cliente.procesar_cola()
        # 0 si no quedo nada pendiente; 2 si hay que reintentar luego.
        return 2 if recuento.get('temporal') else 0

    if orden == 'subir':
        if len(argv) < 3:
            print('Uso: jocotoco_client.py subir <archivo>', file=sys.stderr)
            return 1
        return 0 if cliente.subir(argv[2]) in (Resultado.ENVIADO, Resultado.DUPLICADO) else 1

    if orden == 'estado':
        print(json.dumps(cliente.estado(), indent=2, ensure_ascii=False))
        return 0

    print(__doc__)

    return 0


if __name__ == '__main__':
    sys.exit(main(sys.argv))
