<?php

declare(strict_types=1);

namespace Jocotoco\Storage;

use Jocotoco\Audio\Recording;
use PDO;

/**
 * Acceso a los metadatos de grabaciones y dispositivos.
 */
final class RecordingRepository
{
    public function __construct(private readonly Database $database)
    {
    }

    public function save(Recording $recording): void
    {
        $statement = $this->database->connection()->prepare(
            'INSERT INTO recordings (
                id, device_id, cert_fingerprint, format, media_type, size_bytes,
                sha256, relative_path, original_filename, recorded_at, received_at, metadata
            ) VALUES (
                :id, :device_id, :cert_fingerprint, :format, :media_type, :size_bytes,
                :sha256, :relative_path, :original_filename, :recorded_at, :received_at, :metadata
            )',
        );

        $statement->execute([
            ':id' => $recording->id,
            ':device_id' => $recording->deviceId,
            ':cert_fingerprint' => $recording->certificateFingerprint,
            ':format' => $recording->format,
            ':media_type' => $recording->mediaType,
            ':size_bytes' => $recording->sizeBytes,
            ':sha256' => $recording->sha256,
            ':relative_path' => $recording->relativePath,
            ':original_filename' => $recording->originalFilename,
            ':recorded_at' => $recording->recordedAt,
            ':received_at' => $recording->receivedAt,
            ':metadata' => json_encode($recording->metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
    }

    public function find(string $id): ?Recording
    {
        $statement = $this->database->connection()->prepare('SELECT * FROM recordings WHERE id = :id');
        $statement->execute([':id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? Recording::fromRow($row) : null;
    }

    public function findByChecksum(string $deviceId, string $sha256): ?Recording
    {
        $statement = $this->database->connection()->prepare(
            'SELECT * FROM recordings WHERE device_id = :device_id AND sha256 = :sha256',
        );
        $statement->execute([':device_id' => $deviceId, ':sha256' => $sha256]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? Recording::fromRow($row) : null;
    }

    /**
     * @return list<Recording>
     */
    public function listByDevice(?string $deviceId, int $limit, int $offset = 0, ?string $since = null): array
    {
        $sql = 'SELECT * FROM recordings';
        $conditions = [];
        $parameters = [];

        if ($deviceId !== null && $deviceId !== '') {
            $conditions[] = 'device_id = :device_id';
            $parameters[':device_id'] = $deviceId;
        }

        if ($since !== null && $since !== '') {
            $conditions[] = 'received_at >= :since';
            $parameters[':since'] = $since;
        }

        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        $sql .= ' ORDER BY received_at DESC, id DESC LIMIT :limit OFFSET :offset';

        $statement = $this->database->connection()->prepare($sql);
        foreach ($parameters as $name => $value) {
            $statement->bindValue($name, $value, PDO::PARAM_STR);
        }
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();

        return array_map(
            static fn (array $row): Recording => Recording::fromRow($row),
            $statement->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    public function countByDevice(?string $deviceId): int
    {
        if ($deviceId === null || $deviceId === '') {
            $statement = $this->database->connection()->query('SELECT COUNT(*) FROM recordings');

            return $statement === false ? 0 : (int) $statement->fetchColumn();
        }

        $statement = $this->database->connection()->prepare('SELECT COUNT(*) FROM recordings WHERE device_id = :device_id');
        $statement->execute([':device_id' => $deviceId]);

        return (int) $statement->fetchColumn();
    }

    public function delete(string $id): bool
    {
        $statement = $this->database->connection()->prepare('DELETE FROM recordings WHERE id = :id');
        $statement->execute([':id' => $id]);

        return $statement->rowCount() > 0;
    }

    /**
     * Registra la actividad del dispositivo (primera y ultima conexion,
     * totales acumulados). Sirve para auditoria y para el endpoint de estado.
     */
    public function touchDevice(string $deviceId, string $fingerprint, string $timestamp, int $addedBytes = 0, int $addedRecordings = 0): void
    {
        $statement = $this->database->connection()->prepare(
            'INSERT INTO devices (id, first_seen_at, last_seen_at, last_cert_fingerprint, recordings_count, bytes_stored)
             VALUES (:id, :now, :now, :fingerprint, :recordings, :bytes)
             ON CONFLICT(id) DO UPDATE SET
                last_seen_at = :now,
                last_cert_fingerprint = :fingerprint,
                recordings_count = recordings_count + :recordings,
                bytes_stored = bytes_stored + :bytes',
        );

        $statement->execute([
            ':id' => $deviceId,
            ':now' => $timestamp,
            ':fingerprint' => $fingerprint,
            ':recordings' => $addedRecordings,
            ':bytes' => $addedBytes,
        ]);
    }

    /** @return array<string,mixed>|null */
    public function findDevice(string $deviceId): ?array
    {
        $statement = $this->database->connection()->prepare('SELECT * FROM devices WHERE id = :id');
        $statement->execute([':id' => $deviceId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @return list<array<string,mixed>> */
    public function listDevices(int $limit = 100): array
    {
        $statement = $this->database->connection()->prepare(
            'SELECT * FROM devices ORDER BY last_seen_at DESC LIMIT :limit',
        );
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array{grabaciones:int,bytes:int,dispositivos:int} */
    public function statistics(): array
    {
        $pdo = $this->database->connection();
        $row = $pdo->query('SELECT COUNT(*) AS total, COALESCE(SUM(size_bytes), 0) AS bytes FROM recordings')?->fetch(PDO::FETCH_ASSOC) ?: [];
        $devices = $pdo->query('SELECT COUNT(*) FROM devices')?->fetchColumn() ?: 0;

        return [
            'grabaciones' => (int) ($row['total'] ?? 0),
            'bytes' => (int) ($row['bytes'] ?? 0),
            'dispositivos' => (int) $devices,
        ];
    }
}
