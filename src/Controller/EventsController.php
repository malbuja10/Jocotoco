<?php

declare(strict_types=1);

namespace Jocotoco\Controller;

use Jocotoco\Events\EventRepository;
use Jocotoco\Events\EventService;
use Jocotoco\Exception\HttpException;
use Jocotoco\Http\Request;
use Jocotoco\Http\Response;
use Jocotoco\Security\DeviceIdentity;
use Jocotoco\Support\Ulid;

/**
 * Eventos de deteccion con audio (seccion 3 del contrato del monitor).
 */
final class EventsController
{
    public function __construct(
        private readonly EventService $service,
        private readonly EventRepository $repository,
    ) {
    }

    /** POST /api/sincronizar */
    public function store(Request $request, array $params, DeviceIdentity $device): Response
    {
        $resultado = $this->service->receive($request, $device);
        $evento = $resultado['evento'];

        $cuerpo = [
            // El monitor solo borra su copia local con 200/201; "status" y
            // "event_id" le permiten confirmar que es su evento.
            'status' => 'ok',
            'duplicado' => $resultado['duplicado'],
            'evento' => $evento->toArray(),
        ];

        $cabeceras = ['Location' => '/v1/eventos/' . $evento->id];

        return $resultado['duplicado']
            ? Response::json(200, $cuerpo, $cabeceras + ['Idempotent-Replay' => 'true'])
            : Response::json(201, $cuerpo, $cabeceras);
    }

    /** GET /v1/eventos */
    public function index(Request $request, array $params, DeviceIdentity $device): Response
    {
        $limite = $request->queryInt('limite', 50, 1, 200);
        $desplazamiento = $request->queryInt('desplazamiento', 0, 0, PHP_INT_MAX);
        $clase = $request->query('clase');

        $eventos = $this->repository->listByDevice($device->id, $limite, $desplazamiento, $clase);

        return Response::json(200, [
            'total' => $this->repository->countByDevice($device->id),
            'limite' => $limite,
            'desplazamiento' => $desplazamiento,
            'eventos' => array_map(static fn ($evento): array => $evento->toArray(), $eventos),
        ]);
    }

    /** GET /v1/eventos/{id} */
    public function show(Request $request, array $params, DeviceIdentity $device): Response
    {
        $id = (string) ($params['id'] ?? '');
        if (!Ulid::isValid($id)) {
            throw HttpException::badRequest('El identificador de evento no es valido.');
        }

        $evento = $this->repository->find($id);
        if ($evento === null) {
            throw HttpException::notFound(sprintf('No existe el evento %s.', $id));
        }

        if ($evento->deviceId !== $device->id) {
            throw HttpException::forbidden('El evento pertenece a otro dispositivo.');
        }

        return Response::json(200, [
            'evento' => $evento->toArray(),
            'payload_original' => $evento->payload,
            'ruta_declarada_en_el_dispositivo' => $evento->declaredAudioPath,
        ]);
    }
}
