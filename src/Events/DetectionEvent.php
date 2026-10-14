<?php

declare(strict_types=1);

namespace Jocotoco\Events;

/**
 * Evento de deteccion enviado por el monitor.
 *
 * "threat_type" es la etiqueta que devuelve el modelo, no una amenaza
 * confirmada: se guarda literalmente, junto al puntaje, sin interpretarla.
 */
final class DetectionEvent
{
    /** @param array<string,mixed> $payload */
    public function __construct(
        public readonly string $id,
        public readonly string $deviceId,
        public readonly string $eventId,
        public readonly ?string $threatType,
        public readonly ?float $confidenceScore,
        public readonly ?int $eventTimestamp,
        public readonly ?string $occurredAt,
        public readonly ?float $durationSec,
        public readonly ?int $sampleRate,
        public readonly ?int $channels,
        public readonly ?int $bitDepth,
        public readonly ?string $declaredAudioPath,
        public readonly ?string $recordingId,
        public readonly array $payload,
        public readonly string $receivedAt,
    ) {
    }

    /** @param array<string,mixed> $row */
    public static function fromRow(array $row): self
    {
        $payload = [];
        if (is_string($row['payload'] ?? null) && $row['payload'] !== '') {
            $decoded = json_decode((string) $row['payload'], true);
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        }

        return new self(
            id: (string) $row['id'],
            deviceId: (string) $row['device_id'],
            eventId: (string) $row['event_id'],
            threatType: $row['threat_type'] !== null ? (string) $row['threat_type'] : null,
            confidenceScore: $row['confidence_score'] !== null ? (float) $row['confidence_score'] : null,
            eventTimestamp: $row['event_timestamp'] !== null ? (int) $row['event_timestamp'] : null,
            occurredAt: $row['occurred_at'] !== null ? (string) $row['occurred_at'] : null,
            durationSec: $row['duration_sec'] !== null ? (float) $row['duration_sec'] : null,
            sampleRate: $row['sample_rate'] !== null ? (int) $row['sample_rate'] : null,
            channels: $row['channels'] !== null ? (int) $row['channels'] : null,
            bitDepth: $row['bit_depth'] !== null ? (int) $row['bit_depth'] : null,
            declaredAudioPath: $row['declared_audio_path'] !== null ? (string) $row['declared_audio_path'] : null,
            recordingId: $row['recording_id'] !== null ? (string) $row['recording_id'] : null,
            payload: $payload,
            receivedAt: (string) $row['received_at'],
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'event_id' => $this->eventId,
            'device_id' => $this->deviceId,
            'threat_type' => $this->threatType,
            'confidence_score' => $this->confidenceScore,
            'timestamp' => $this->eventTimestamp,
            'ocurrido_en' => $this->occurredAt,
            'audio' => [
                'grabacion_id' => $this->recordingId,
                'duration_sec' => $this->durationSec,
                'sample_rate' => $this->sampleRate,
                'channels' => $this->channels,
                'bit_depth' => $this->bitDepth,
                'descarga' => $this->recordingId !== null
                    ? '/v1/grabaciones/' . $this->recordingId . '/audio'
                    : null,
            ],
            'recibido_en' => $this->receivedAt,
        ];
    }
}
