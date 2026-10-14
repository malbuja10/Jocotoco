<?php

declare(strict_types=1);

namespace Jocotoco\Events;

use Jocotoco\Storage\Database;
use PDO;

final class EventRepository
{
    public function __construct(private readonly Database $database)
    {
    }

    public function save(DetectionEvent $evento): void
    {
        $statement = $this->database->connection()->prepare(
            'INSERT INTO detection_events (
                id, device_id, event_id, threat_type, confidence_score, event_timestamp,
                occurred_at, duration_sec, sample_rate, channels, bit_depth,
                declared_audio_path, recording_id, payload, received_at
            ) VALUES (
                :id, :device_id, :event_id, :threat_type, :confidence_score, :event_timestamp,
                :occurred_at, :duration_sec, :sample_rate, :channels, :bit_depth,
                :declared_audio_path, :recording_id, :payload, :received_at
            )',
        );

        $statement->execute([
            ':id' => $evento->id,
            ':device_id' => $evento->deviceId,
            ':event_id' => $evento->eventId,
            ':threat_type' => $evento->threatType,
            ':confidence_score' => $evento->confidenceScore,
            ':event_timestamp' => $evento->eventTimestamp,
            ':occurred_at' => $evento->occurredAt,
            ':duration_sec' => $evento->durationSec,
            ':sample_rate' => $evento->sampleRate,
            ':channels' => $evento->channels,
            ':bit_depth' => $evento->bitDepth,
            ':declared_audio_path' => $evento->declaredAudioPath,
            ':recording_id' => $evento->recordingId,
            ':payload' => json_encode($evento->payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ':received_at' => $evento->receivedAt,
        ]);
    }

    public function findByEventId(string $deviceId, string $eventId): ?DetectionEvent
    {
        $statement = $this->database->connection()->prepare(
            'SELECT * FROM detection_events WHERE device_id = :device_id AND event_id = :event_id',
        );
        $statement->execute([':device_id' => $deviceId, ':event_id' => $eventId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? DetectionEvent::fromRow($row) : null;
    }

    public function find(string $id): ?DetectionEvent
    {
        $statement = $this->database->connection()->prepare('SELECT * FROM detection_events WHERE id = :id');
        $statement->execute([':id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? DetectionEvent::fromRow($row) : null;
    }

    /** @return list<DetectionEvent> */
    public function listByDevice(?string $deviceId, int $limit, int $offset = 0, ?string $clase = null): array
    {
        $sql = 'SELECT * FROM detection_events';
        $condiciones = [];
        $parametros = [];

        if ($deviceId !== null && $deviceId !== '') {
            $condiciones[] = 'device_id = :device_id';
            $parametros[':device_id'] = $deviceId;
        }

        if ($clase !== null && $clase !== '') {
            $condiciones[] = 'threat_type = :clase';
            $parametros[':clase'] = $clase;
        }

        if ($condiciones !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $condiciones);
        }

        $sql .= ' ORDER BY received_at DESC, id DESC LIMIT :limit OFFSET :offset';

        $statement = $this->database->connection()->prepare($sql);
        foreach ($parametros as $nombre => $valor) {
            $statement->bindValue($nombre, $valor, PDO::PARAM_STR);
        }
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();

        return array_map(
            static fn (array $row): DetectionEvent => DetectionEvent::fromRow($row),
            $statement->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    public function countByDevice(?string $deviceId): int
    {
        if ($deviceId === null || $deviceId === '') {
            $statement = $this->database->connection()->query('SELECT COUNT(*) FROM detection_events');

            return $statement === false ? 0 : (int) $statement->fetchColumn();
        }

        $statement = $this->database->connection()->prepare(
            'SELECT COUNT(*) FROM detection_events WHERE device_id = :device_id',
        );
        $statement->execute([':device_id' => $deviceId]);

        return (int) $statement->fetchColumn();
    }
}
