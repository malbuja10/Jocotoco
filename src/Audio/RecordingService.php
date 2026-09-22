<?php

declare(strict_types=1);

namespace Jocotoco\Audio;

use Jocotoco\Exception\HttpException;
use Jocotoco\Exception\StorageException;
use Jocotoco\Http\Request;
use Jocotoco\Security\DeviceIdentity;
use Jocotoco\Storage\AudioStorage;
use Jocotoco\Storage\RecordingRepository;
use Jocotoco\Support\Clock;
use Jocotoco\Support\Logger;
use Jocotoco\Support\Ulid;
use PDOException;

/**
 * Caso de uso principal: recibir una grabacion de un dispositivo autenticado.
 */
final class RecordingService
{
    public function __construct(
        private readonly PayloadReader $reader,
        private readonly AudioValidator $validator,
        private readonly AudioStorage $storage,
        private readonly RecordingRepository $repository,
        private readonly Clock $clock,
        private readonly Logger $logger,
    ) {
    }

    public function receive(Request $request, DeviceIdentity $device): StoreRecordingResult
    {
        $payload = $this->reader->read($request);

        try {
            $format = $this->validator->validateFile($payload->temporaryPath, $payload->declaredMediaType);
            $sha256 = $this->validator->verifyChecksum($payload->temporaryPath, $request->header('x-audio-sha256'));

            $existing = $this->repository->findByChecksum($device->id, $sha256);
            if ($existing !== null) {
                $this->logger->info('Grabacion duplicada ignorada', [
                    'dispositivo' => $device->id,
                    'grabacion' => $existing->id,
                    'sha256' => $sha256,
                ]);

                return new StoreRecordingResult($existing, true);
            }

            $now = $this->clock->now();
            $fields = array_merge($payload->fields, $request->parsedBody());
            $metadata = $this->validator->normalizeMetadata($fields);
            $recordedAt = $this->validator->normalizeRecordedAt($fields['grabado_en'] ?? null, $now);

            $size = (int) filesize($payload->temporaryPath);
            $id = Ulid::generate($this->clock);

            try {
                $relativePath = $this->storage->store($payload->temporaryPath, $device->id, $id, $format);
            } catch (StorageException $exception) {
                $this->logger->error('Fallo el almacenamiento del audio', [
                    'dispositivo' => $device->id,
                    'error' => $exception->getMessage(),
                ]);

                throw HttpException::serverError('No se pudo almacenar la grabacion.', $exception);
            }

            $recording = new Recording(
                id: $id,
                deviceId: $device->id,
                certificateFingerprint: $device->certificateFingerprint(),
                format: $format->name,
                mediaType: $format->canonicalMediaType,
                sizeBytes: $size,
                sha256: $sha256,
                relativePath: $relativePath,
                originalFilename: $payload->clientFilename,
                recordedAt: $recordedAt,
                receivedAt: gmdate('Y-m-d\TH:i:s\Z', $now),
                metadata: $metadata,
            );

            try {
                $this->repository->save($recording);
            } catch (PDOException $exception) {
                // La carrera de dos envios identicos la resuelve el indice unico.
                $duplicate = $this->repository->findByChecksum($device->id, $sha256);
                $this->storage->delete($relativePath);

                if ($duplicate !== null) {
                    return new StoreRecordingResult($duplicate, true);
                }

                throw HttpException::serverError('No se pudo registrar la grabacion.', $exception);
            }

            $this->repository->touchDevice(
                $device->id,
                $device->certificateFingerprint(),
                $recording->receivedAt,
                $size,
                1,
            );

            $this->logger->info('Grabacion recibida', [
                'dispositivo' => $device->id,
                'grabacion' => $id,
                'formato' => $format->name,
                'tamano_bytes' => $size,
                'ruta' => $relativePath,
            ]);

            return new StoreRecordingResult($recording, false);
        } finally {
            $payload->discard();
        }
    }

    public function find(string $id): Recording
    {
        if (!Ulid::isValid($id)) {
            throw HttpException::badRequest('El identificador de grabacion no es valido.');
        }

        $recording = $this->repository->find($id);
        if ($recording === null) {
            throw HttpException::notFound(sprintf('No existe la grabacion %s.', $id));
        }

        return $recording;
    }

    /**
     * Verifica que el dispositivo solo acceda a sus propias grabaciones,
     * salvo que se trate de un cliente con rol de consulta global.
     */
    public function findForDevice(string $id, DeviceIdentity $device, bool $allowCrossDevice): Recording
    {
        $recording = $this->find($id);

        if (!$allowCrossDevice && $recording->deviceId !== $device->id) {
            throw HttpException::forbidden('La grabacion pertenece a otro dispositivo.');
        }

        return $recording;
    }

    public function audioPath(Recording $recording): string
    {
        if (!$this->storage->exists($recording->relativePath)) {
            throw HttpException::notFound(sprintf(
                'El archivo de la grabacion %s no se encuentra en el almacenamiento.',
                $recording->id,
            ));
        }

        return $this->storage->absolutePath($recording->relativePath);
    }
}
