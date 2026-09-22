<?php

declare(strict_types=1);

namespace Jocotoco\Controller;

use Jocotoco\Audio\AudioValidator;
use Jocotoco\Audio\RecordingService;
use Jocotoco\Config\Config;
use Jocotoco\Exception\HttpException;
use Jocotoco\Http\Request;
use Jocotoco\Http\Response;
use Jocotoco\Security\DeviceIdentity;
use Jocotoco\Storage\RecordingRepository;

/**
 * Endpoints de grabaciones.
 */
final class RecordingsController
{
    public function __construct(
        private readonly RecordingService $service,
        private readonly RecordingRepository $repository,
        private readonly AudioValidator $validator,
        private readonly Config $config,
    ) {
    }

    /** POST /v1/grabaciones */
    public function store(Request $request, array $params, DeviceIdentity $device): Response
    {
        $result = $this->service->receive($request, $device);
        $recording = $result->recording;

        $headers = [
            'Location' => '/v1/grabaciones/' . $recording->id,
            'ETag' => '"' . $recording->sha256 . '"',
        ];

        if ($result->duplicate) {
            return Response::json(200, [
                'mensaje' => 'La grabacion ya habia sido recibida; no se duplico.',
                'duplicado' => true,
                'grabacion' => $recording->toArray(),
            ], $headers + ['Idempotent-Replay' => 'true']);
        }

        return Response::json(201, [
            'mensaje' => 'Grabacion almacenada.',
            'duplicado' => false,
            'grabacion' => $recording->toArray(),
        ], $headers);
    }

    /** GET /v1/grabaciones */
    public function index(Request $request, array $params, DeviceIdentity $device): Response
    {
        $crossDevice = $this->allowsCrossDevice($device);
        $requested = $request->query('dispositivo');

        if ($requested !== null && $requested !== $device->id && !$crossDevice) {
            throw HttpException::forbidden('No puede consultar grabaciones de otros dispositivos.');
        }

        $filter = $crossDevice ? $requested : $device->id;
        $limit = $request->queryInt('limite', 50, 1, 200);
        $offset = $request->queryInt('desplazamiento', 0, 0, PHP_INT_MAX);
        $since = $request->query('desde');

        $recordings = $this->repository->listByDevice($filter, $limit, $offset, $since);

        return Response::json(200, [
            'total' => $this->repository->countByDevice($filter),
            'limite' => $limit,
            'desplazamiento' => $offset,
            'grabaciones' => array_map(static fn ($item): array => $item->toArray(), $recordings),
        ]);
    }

    /** GET /v1/grabaciones/{id} */
    public function show(Request $request, array $params, DeviceIdentity $device): Response
    {
        $recording = $this->service->findForDevice(
            (string) ($params['id'] ?? ''),
            $device,
            $this->allowsCrossDevice($device),
        );

        return Response::json(200, ['grabacion' => $recording->toArray()]);
    }

    /** GET /v1/grabaciones/{id}/audio */
    public function download(Request $request, array $params, DeviceIdentity $device): Response
    {
        $recording = $this->service->findForDevice(
            (string) ($params['id'] ?? ''),
            $device,
            $this->allowsCrossDevice($device),
        );

        $path = $this->service->audioPath($recording);

        if (trim($request->header('if-none-match'), '"') === $recording->sha256) {
            return Response::text(304, '')->withHeaders(['ETag' => '"' . $recording->sha256 . '"']);
        }

        return Response::file($path, $recording->mediaType, $recording->downloadName(), [
            'ETag' => '"' . $recording->sha256 . '"',
            'X-Audio-SHA256' => $recording->sha256,
        ]);
    }

    /** DELETE /v1/grabaciones/{id} */
    public function destroy(Request $request, array $params, DeviceIdentity $device): Response
    {
        if (!$this->config->bool('allow_delete')) {
            throw HttpException::forbidden('El borrado de grabaciones esta deshabilitado en este API.');
        }

        $recording = $this->service->findForDevice(
            (string) ($params['id'] ?? ''),
            $device,
            $this->allowsCrossDevice($device),
        );

        $this->repository->delete($recording->id);
        $this->repository->touchDevice(
            $recording->deviceId,
            $device->certificateFingerprint(),
            gmdate('Y-m-d\TH:i:s\Z'),
            -$recording->sizeBytes,
            -1,
        );

        return Response::noContent();
    }

    /** GET /v1/dispositivos */
    public function devices(Request $request, array $params, DeviceIdentity $device): Response
    {
        if (!$this->allowsCrossDevice($device)) {
            throw HttpException::forbidden('Solo los clientes de consulta pueden listar dispositivos.');
        }

        return Response::json(200, [
            'dispositivos' => array_map(static fn (array $row): array => [
                'id' => $row['id'],
                'primera_conexion' => $row['first_seen_at'],
                'ultima_conexion' => $row['last_seen_at'],
                'huella_certificado' => $row['last_cert_fingerprint'],
                'grabaciones' => (int) $row['recordings_count'],
                'bytes_almacenados' => (int) $row['bytes_stored'],
            ], $this->repository->listDevices()),
        ]);
    }

    /** GET /v1/limites: lo que el dispositivo necesita saber antes de enviar. */
    public function limits(Request $request, array $params, DeviceIdentity $device): Response
    {
        return Response::json(200, [
            'maximo_bytes' => $this->validator->maxBytes(),
            'minimo_bytes' => $this->validator->minBytes(),
            'formatos_permitidos' => $this->validator->allowedFormats(),
            'campo_multipart' => 'audio',
            'cabeceras_opcionales' => [
                'X-Audio-SHA256' => 'sha256 del archivo, para verificar integridad',
                'X-Grabado-En' => 'fecha ISO 8601 de la grabacion',
                'X-Nombre-Archivo' => 'nombre original del archivo',
                'X-Metadatos' => 'objeto JSON con sitio, estacion, latitud, longitud, etc.',
            ],
        ]);
    }

    private function allowsCrossDevice(DeviceIdentity $device): bool
    {
        return in_array($device->id, $this->config->list('reader_devices'), true);
    }
}
