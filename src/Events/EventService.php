<?php

declare(strict_types=1);

namespace Jocotoco\Events;

use Jocotoco\Audio\RecordingService;
use Jocotoco\Audio\WavInfo;
use Jocotoco\Audio\WavInspector;
use Jocotoco\Config\Config;
use Jocotoco\Exception\HttpException;
use Jocotoco\Http\Request;
use Jocotoco\Security\DeviceIdentity;
use Jocotoco\Support\Clock;
use Jocotoco\Support\Logger;
use Jocotoco\Support\Ulid;
use PDOException;

/**
 * Recepcion de eventos de deteccion con su audio.
 *
 * Implementa la seccion 3 del contrato del monitor: multipart con una parte
 * "data" (JSON serializado) y una parte "audio" (WAV binario).
 *
 * Decisiones que el contrato deja al servidor:
 *
 *  - el audio manda sobre lo declarado: duracion, frecuencia, canales y
 *    profundidad se toman de la cabecera del WAV, no del JSON, y se avisa en
 *    el log cuando no coinciden;
 *  - "audio_path" se guarda solo como referencia; el audio vive bajo el
 *    identificador de la grabacion, nunca se intenta leer esa ruta;
 *  - la identidad del certificado manda sobre "device_id": si el JSON
 *    declara otro dispositivo, se rechaza;
 *  - repetir un envio devuelve 200 con el evento ya guardado, para que el
 *    monitor pueda borrar su copia local sin perder nada.
 */
final class EventService
{
    public function __construct(
        private readonly Config $config,
        private readonly EventRepository $repository,
        private readonly RecordingService $recordings,
        private readonly WavInspector $inspector,
        private readonly Clock $clock,
        private readonly Logger $logger,
    ) {
    }

    /** @return array{evento:DetectionEvent,duplicado:bool} */
    public function receive(Request $request, DeviceIdentity $device): array
    {
        if (!str_starts_with($request->contentType(), 'multipart/form-data')) {
            throw HttpException::unsupportedMediaType(
                'El evento debe enviarse como multipart/form-data con las partes "data" y "audio".',
            );
        }

        $datos = $this->leerParteData($request);
        $eventId = $this->validarEventId($datos['event_id'] ?? null);

        $this->comprobarDispositivo($datos['device_id'] ?? null, $device);

        // Antes de tocar el audio: si el evento ya esta, se responde con el
        // existente. El monitor reintenta cuando pierde la respuesta.
        $existente = $this->repository->findByEventId($device->id, $eventId);
        if ($existente !== null) {
            $this->logger->info('Evento duplicado ignorado', [
                'dispositivo' => $device->id,
                'event_id' => $eventId,
                'evento' => $existente->id,
            ]);

            return ['evento' => $existente, 'duplicado' => true];
        }

        $archivo = $request->file('audio');
        if ($archivo === null || !$archivo->isValid()) {
            $recibidas = array_map(static fn ($item): string => $item->field, $request->files());

            throw HttpException::badRequest(
                $archivo === null
                    ? 'Falta la parte "audio" con el archivo WAV.'
                    : $archivo->errorMessage(),
                ['partes_recibidas' => array_values($recibidas)],
            );
        }

        $info = $this->inspector->inspect($archivo->temporaryPath);
        if ($info === null) {
            throw HttpException::unsupportedMediaType('La parte "audio" no es un WAV legible.');
        }

        $this->validarAudio($info);
        $this->avisarDiferencias($datos, $info, $device->id, $eventId);

        $resultado = $this->recordings->storeFile(
            $archivo->temporaryPath,
            $device,
            [
                'duracion_segundos' => $info->duracionSegundos,
                'frecuencia_muestreo' => $info->frecuenciaMuestreo,
                'canales' => $info->canales,
            ],
            $archivo->clientFilename,
            $archivo->clientMediaType,
        );

        $ahora = $this->clock->now();
        $marca = $this->normalizarMarcaDeTiempo($datos['timestamp'] ?? null, $ahora);

        $evento = new DetectionEvent(
            id: Ulid::generate($this->clock),
            deviceId: $device->id,
            eventId: $eventId,
            threatType: $this->texto($datos['threat_type'] ?? null, 64),
            confidenceScore: $this->numero($datos['confidence_score'] ?? null, 'confidence_score'),
            eventTimestamp: $marca,
            occurredAt: $marca !== null ? gmdate('Y-m-d\TH:i:s\Z', $marca) : null,
            durationSec: $info->duracionSegundos,
            sampleRate: $info->frecuenciaMuestreo,
            channels: $info->canales,
            bitDepth: $info->bitsPorMuestra,
            declaredAudioPath: $this->texto($datos['audio_path'] ?? null, 255),
            recordingId: $resultado->recording->id,
            payload: $datos,
            receivedAt: gmdate('Y-m-d\TH:i:s\Z', $ahora),
        );

        try {
            $this->repository->save($evento);
        } catch (PDOException $exception) {
            // Dos envios simultaneos del mismo evento: gana el indice unico.
            $duplicado = $this->repository->findByEventId($device->id, $eventId);
            if ($duplicado !== null) {
                return ['evento' => $duplicado, 'duplicado' => true];
            }

            throw HttpException::serverError('No se pudo registrar el evento.', $exception);
        }

        $this->logger->info('Evento de deteccion recibido', [
            'dispositivo' => $device->id,
            'event_id' => $eventId,
            'clase' => $evento->threatType,
            'puntaje' => $evento->confidenceScore,
            'grabacion' => $resultado->recording->id,
            'duracion_sec' => $info->duracionSegundos,
        ]);

        return ['evento' => $evento, 'duplicado' => false];
    }

    /** @return array<string,mixed> */
    private function leerParteData(Request $request): array
    {
        $bruto = $request->input('data');
        if ($bruto === null || trim($bruto) === '') {
            throw HttpException::badRequest('Falta la parte "data" con el JSON del evento.');
        }

        if (strlen($bruto) > $this->config->int('event_max_data_bytes')) {
            throw HttpException::payloadTooLarge('La parte "data" es demasiado grande.');
        }

        $datos = json_decode($bruto, true);
        if (!is_array($datos)) {
            throw HttpException::badRequest('La parte "data" no contiene un objeto JSON valido.');
        }

        return $datos;
    }

    private function validarEventId(mixed $valor): string
    {
        $eventId = is_scalar($valor) ? trim((string) $valor) : '';

        if ($eventId === '' || preg_match('/^[A-Za-z0-9._:-]{8,64}$/', $eventId) !== 1) {
            throw HttpException::badRequest(
                'El campo "event_id" es obligatorio (8 a 64 caracteres alfanumericos, punto, guion, guion bajo o dos puntos).',
            );
        }

        return $eventId;
    }

    private function comprobarDispositivo(mixed $declarado, DeviceIdentity $device): void
    {
        if (!is_scalar($declarado) || trim((string) $declarado) === '') {
            return;
        }

        if (trim((string) $declarado) !== $device->id) {
            throw HttpException::forbidden(sprintf(
                'El "device_id" del evento (%s) no corresponde al certificado de cliente (%s).',
                trim((string) $declarado),
                $device->id,
            ));
        }
    }

    private function validarAudio(WavInfo $info): void
    {
        if (!$info->esPcm()) {
            throw HttpException::unsupportedMediaType('El WAV debe ser PCM o IEEE float.');
        }

        $maxDuracion = $this->config->int('event_max_duration_seconds');
        if ($info->duracionSegundos > $maxDuracion) {
            throw HttpException::unprocessable(
                sprintf('El audio dura %.1f s y el maximo es %d s.', $info->duracionSegundos, $maxDuracion),
                ['duracion_sec' => $info->duracionSegundos, 'maximo_sec' => $maxDuracion],
            );
        }

        if ($info->duracionSegundos <= 0.0) {
            throw HttpException::unprocessable('El audio no contiene muestras.');
        }

        $frecuencias = array_map(intval(...), $this->config->list('event_allowed_sample_rates'));
        if ($frecuencias !== [] && !in_array($info->frecuenciaMuestreo, $frecuencias, true)) {
            throw HttpException::unprocessable(
                sprintf('La frecuencia de muestreo %d Hz no esta permitida.', $info->frecuenciaMuestreo),
                ['sample_rate' => $info->frecuenciaMuestreo, 'permitidas' => $frecuencias],
            );
        }

        $maxCanales = $this->config->int('event_max_channels');
        if ($info->canales > $maxCanales) {
            throw HttpException::unprocessable(
                sprintf('El audio tiene %d canales y el maximo es %d.', $info->canales, $maxCanales),
            );
        }
    }

    /**
     * El contrato advierte que los valores del JSON los calcula el propio
     * dispositivo. No se rechaza el evento por una diferencia, pero queda
     * constancia: suele indicar un problema en el monitor.
     *
     * @param array<string,mixed> $datos
     */
    private function avisarDiferencias(array $datos, WavInfo $info, string $deviceId, string $eventId): void
    {
        $comparaciones = [
            'sample_rate' => [$datos['sample_rate'] ?? null, $info->frecuenciaMuestreo],
            'channels' => [$datos['channels'] ?? null, $info->canales],
            'bit_depth' => [$datos['bit_depth'] ?? null, $info->bitsPorMuestra],
        ];

        foreach ($comparaciones as $campo => [$declarado, $real]) {
            if (is_numeric($declarado) && (int) $declarado !== $real) {
                $this->logger->warning('El evento declara un valor distinto al del WAV', [
                    'dispositivo' => $deviceId,
                    'event_id' => $eventId,
                    'campo' => $campo,
                    'declarado' => (int) $declarado,
                    'real' => $real,
                ]);
            }
        }

        $duracion = $datos['duration_sec'] ?? null;
        if (is_numeric($duracion) && abs((float) $duracion - $info->duracionSegundos) > 0.5) {
            $this->logger->warning('El evento declara una duracion distinta a la del WAV', [
                'dispositivo' => $deviceId,
                'event_id' => $eventId,
                'declarado' => (float) $duracion,
                'real' => $info->duracionSegundos,
            ]);
        }
    }

    /**
     * El contrato dice "normalmente epoch en segundos". Se acepta tambien en
     * milisegundos, que es el error mas comun, y se rechaza lo que no sea
     * una fecha plausible.
     */
    private function normalizarMarcaDeTiempo(mixed $valor, int $ahora): ?int
    {
        if (!is_numeric($valor)) {
            return $ahora;
        }

        $marca = (int) $valor;

        if ($marca > 100_000_000_000) {   // viene en milisegundos
            $marca = intdiv($marca, 1000);
        }

        $tolerancia = $this->config->int('recorded_at_future_tolerance_seconds');
        if ($marca < 946_684_800 || $marca > $ahora + $tolerancia) {   // antes de 2000 o en el futuro
            throw HttpException::badRequest(
                sprintf('El campo "timestamp" no es una fecha plausible (%d).', $marca),
            );
        }

        return $marca;
    }

    private function texto(mixed $valor, int $maximo): ?string
    {
        if (!is_scalar($valor)) {
            return null;
        }

        $texto = trim((string) $valor);

        return $texto === '' ? null : mb_substr($texto, 0, $maximo);
    }

    private function numero(mixed $valor, string $campo): ?float
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        if (!is_numeric($valor)) {
            throw HttpException::badRequest(sprintf('El campo "%s" debe ser numerico.', $campo));
        }

        return (float) $valor;
    }
}
