<?php

declare(strict_types=1);

namespace Jocotoco\Audio;

/**
 * Grabacion almacenada.
 */
final class Recording
{
    /** @param array<string,mixed> $metadata */
    public function __construct(
        public readonly string $id,
        public readonly string $deviceId,
        public readonly string $certificateFingerprint,
        public readonly string $format,
        public readonly string $mediaType,
        public readonly int $sizeBytes,
        public readonly string $sha256,
        public readonly string $relativePath,
        public readonly ?string $originalFilename,
        public readonly ?string $recordedAt,
        public readonly string $receivedAt,
        public readonly array $metadata = [],
    ) {
    }

    /** @param array<string,mixed> $row */
    public static function fromRow(array $row): self
    {
        $metadata = [];
        if (is_string($row['metadata'] ?? null) && $row['metadata'] !== '') {
            $decoded = json_decode((string) $row['metadata'], true);
            if (is_array($decoded)) {
                $metadata = $decoded;
            }
        }

        return new self(
            id: (string) $row['id'],
            deviceId: (string) $row['device_id'],
            certificateFingerprint: (string) $row['cert_fingerprint'],
            format: (string) $row['format'],
            mediaType: (string) $row['media_type'],
            sizeBytes: (int) $row['size_bytes'],
            sha256: (string) $row['sha256'],
            relativePath: (string) $row['relative_path'],
            originalFilename: $row['original_filename'] !== null ? (string) $row['original_filename'] : null,
            recordedAt: $row['recorded_at'] !== null ? (string) $row['recorded_at'] : null,
            receivedAt: (string) $row['received_at'],
            metadata: $metadata,
        );
    }

    public function downloadName(): string
    {
        return sprintf('%s-%s.%s', $this->deviceId, $this->id, $this->format);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'dispositivo' => $this->deviceId,
            'formato' => $this->format,
            'tipo_medio' => $this->mediaType,
            'tamano_bytes' => $this->sizeBytes,
            'sha256' => $this->sha256,
            'nombre_original' => $this->originalFilename,
            'grabado_en' => $this->recordedAt,
            'recibido_en' => $this->receivedAt,
            'huella_certificado' => $this->certificateFingerprint,
            'metadatos' => $this->metadata,
            'enlaces' => [
                'detalle' => '/v1/grabaciones/' . $this->id,
                'descarga' => '/v1/grabaciones/' . $this->id . '/audio',
            ],
        ];
    }
}
