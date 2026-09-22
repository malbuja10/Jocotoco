<?php

declare(strict_types=1);

namespace Jocotoco\Controller;

use Jocotoco\Http\Request;
use Jocotoco\Http\Response;
use Jocotoco\Security\DeviceIdentity;
use Jocotoco\Storage\RecordingRepository;
use Jocotoco\Support\Clock;

/**
 * Devuelve la identidad con la que el API ve al dispositivo. Es la ruta que
 * conviene usar desde la Raspberry para verificar que el certificado emitido
 * por step-ca funciona y cuanto le falta para expirar.
 */
final class IdentityController
{
    public function __construct(
        private readonly RecordingRepository $repository,
        private readonly Clock $clock,
    ) {
    }

    public function __invoke(Request $request, array $params, DeviceIdentity $device): Response
    {
        $certificate = $device->certificate;
        $stored = $this->repository->findDevice($device->id);

        return Response::json(200, [
            'dispositivo' => $device->id,
            'certificado' => $certificate->toArray(),
            'expira_en_segundos' => $certificate->secondsUntilExpiry($this->clock->now()),
            'historial' => $stored === null ? null : [
                'primera_conexion' => $stored['first_seen_at'] ?? null,
                'ultima_conexion' => $stored['last_seen_at'] ?? null,
                'grabaciones' => (int) ($stored['recordings_count'] ?? 0),
                'bytes_almacenados' => (int) ($stored['bytes_stored'] ?? 0),
            ],
        ]);
    }
}
