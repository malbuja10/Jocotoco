<?php

declare(strict_types=1);

namespace Jocotoco\Controller;

use Jocotoco\Config\Config;
use Jocotoco\Exception\HttpException;
use Jocotoco\Http\Request;
use Jocotoco\Http\Response;
use Jocotoco\Security\DeviceIdentity;
use Jocotoco\Telemetry\TelemetryRepository;
use Jocotoco\Telemetry\TelemetryService;
use JsonException;

/**
 * Voltaje y estado del dispositivo (seccion 4 del contrato del monitor).
 */
final class TelemetryController
{
    public function __construct(
        private readonly TelemetryService $service,
        private readonly TelemetryRepository $repository,
        private readonly Config $config,
    ) {
    }

    /** POST /api/telemetry/voltage */
    public function voltage(Request $request, array $params, DeviceIdentity $device): Response
    {
        return Response::json(200, $this->service->voltage($this->leerJson($request), $device));
    }

    /** POST /api/telemetry/status */
    public function status(Request $request, array $params, DeviceIdentity $device): Response
    {
        return Response::json(200, $this->service->status($this->leerJson($request), $device));
    }

    /** GET /v1/telemetria: lo ultimo recibido de este dispositivo. */
    public function latest(Request $request, array $params, DeviceIdentity $device): Response
    {
        $limite = $request->queryInt('limite', 20, 1, 200);

        return Response::json(200, [
            'dispositivo' => $device->id,
            'voltaje' => $this->repository->lastVoltage($device->id, $limite),
            'estado' => $this->repository->lastStatus($device->id, $limite),
        ]);
    }

    /** @return array<string,mixed> */
    private function leerJson(Request $request): array
    {
        try {
            $datos = $request->json($this->config->int('telemetry_max_body_bytes'));
        } catch (JsonException $exception) {
            if ($exception->getCode() === 413) {
                throw HttpException::payloadTooLarge($exception->getMessage());
            }

            throw HttpException::badRequest('El cuerpo debe ser un objeto JSON valido.');
        }

        if ($datos === []) {
            throw HttpException::badRequest('Se esperaba un cuerpo JSON con los registros.');
        }

        return $datos;
    }
}
