<?php

declare(strict_types=1);

namespace Jocotoco\Controller;

use Jocotoco\Enrollment\EnrollmentService;
use Jocotoco\Exception\HttpException;
use Jocotoco\Http\Request;
use Jocotoco\Http\Response;

/**
 * Alta automatica de dispositivos: entrega un token de un solo uso con el
 * que el equipo pide su certificado a step-ca.
 *
 * Es, junto con /v1/salud, la unica ruta que no exige certificado de
 * cliente: el dispositivo todavia no tiene ninguno.
 */
final class EnrollmentController
{
    private const MAX_CUERPO_BYTES = 8192;

    public function __construct(private readonly EnrollmentService $service)
    {
    }

    public function __invoke(Request $request, array $params = []): Response
    {
        $datos = $this->leerJson($request);

        // Se aceptan los nombres en ingles (los que usa el cliente Python)
        // y sus equivalentes en espanol.
        $dispositivo = $datos['device_id'] ?? $datos['dispositivo'] ?? null;
        $secreto = $datos['factory_secret'] ?? $datos['secreto_fabrica'] ?? null;

        $resultado = $this->service->enroll(
            is_scalar($dispositivo) ? trim((string) $dispositivo) : null,
            is_scalar($secreto) ? (string) $secreto : null,
            $this->ip($request),
        );

        return Response::json(200, $resultado);
    }

    /** @return array<string,mixed> */
    private function leerJson(Request $request): array
    {
        $cuerpo = $request->body();
        if (!is_resource($cuerpo)) {
            throw HttpException::badRequest('Se esperaba un cuerpo JSON con device_id y factory_secret.');
        }

        $bruto = (string) stream_get_contents($cuerpo, self::MAX_CUERPO_BYTES + 1);

        if (strlen($bruto) > self::MAX_CUERPO_BYTES) {
            throw HttpException::payloadTooLarge('El cuerpo de la solicitud de inscripcion es demasiado grande.');
        }

        if (trim($bruto) === '') {
            throw HttpException::badRequest('Se esperaba un cuerpo JSON con device_id y factory_secret.');
        }

        $datos = json_decode($bruto, true);
        if (!is_array($datos)) {
            throw HttpException::badRequest('El cuerpo debe ser un objeto JSON.');
        }

        return $datos;
    }

    private function ip(Request $request): string
    {
        $ip = $request->server('REMOTE_ADDR', 'desconocida');

        return $ip === '' ? 'desconocida' : $ip;
    }
}
