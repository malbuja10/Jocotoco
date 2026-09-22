<?php

declare(strict_types=1);

namespace Jocotoco\Controller;

use Jocotoco\Http\Request;
use Jocotoco\Http\Response;
use Jocotoco\Storage\AudioStorage;
use Jocotoco\Storage\Database;
use Jocotoco\Storage\RecordingRepository;
use Jocotoco\Support\Clock;

/**
 * Sonda de estado para monitoreo. Es la unica ruta que no exige certificado
 * de cliente, para que un chequeo externo pueda consultarla sobre TLS.
 */
final class HealthController
{
    public function __construct(
        private readonly Database $database,
        private readonly AudioStorage $storage,
        private readonly RecordingRepository $repository,
        private readonly Clock $clock,
        private readonly string $version,
    ) {
    }

    public function __invoke(Request $request, array $params = []): Response
    {
        $databaseOk = $this->database->isHealthy();
        $storageOk = is_dir($this->storage->rootDirectory()) && is_writable($this->storage->rootDirectory());
        $free = $this->storage->freeBytes();

        $payload = [
            'estado' => $databaseOk && $storageOk ? 'ok' : 'degradado',
            'version' => $this->version,
            'hora' => $this->clock->nowIso8601(),
            'php' => PHP_VERSION,
            'comprobaciones' => [
                'base_datos' => $databaseOk ? 'ok' : 'error',
                'almacenamiento' => $storageOk ? 'ok' : 'error',
            ],
            'almacenamiento' => [
                'raiz' => $this->storage->rootDirectory(),
                'bytes_libres' => $free,
            ],
        ];

        if ($databaseOk) {
            $payload['totales'] = $this->repository->statistics();
        }

        return Response::json($databaseOk && $storageOk ? 200 : 503, $payload);
    }
}
