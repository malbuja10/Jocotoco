<?php

declare(strict_types=1);

namespace Jocotoco\Http;

/**
 * Archivo recibido en un multipart/form-data.
 */
final class UploadedFile
{
    public function __construct(
        public readonly string $field,
        public readonly string $temporaryPath,
        public readonly ?string $clientFilename,
        public readonly ?string $clientMediaType,
        public readonly int $size,
        public readonly int $error = UPLOAD_ERR_OK,
    ) {
    }

    /**
     * Normaliza $_FILES (incluyendo campos con notacion de arreglo) a una lista plana.
     *
     * @param array<string,mixed> $files
     * @return list<self>
     */
    public static function fromFilesSuperglobal(array $files): array
    {
        $normalized = [];

        foreach ($files as $field => $entry) {
            if (!is_array($entry) || !isset($entry['tmp_name'])) {
                continue;
            }

            if (is_array($entry['tmp_name'])) {
                foreach (array_keys($entry['tmp_name']) as $index) {
                    $normalized[] = new self(
                        (string) $field,
                        (string) $entry['tmp_name'][$index],
                        self::stringOrNull($entry['name'][$index] ?? null),
                        self::stringOrNull($entry['type'][$index] ?? null),
                        (int) ($entry['size'][$index] ?? 0),
                        (int) ($entry['error'][$index] ?? UPLOAD_ERR_OK),
                    );
                }
                continue;
            }

            $normalized[] = new self(
                (string) $field,
                (string) $entry['tmp_name'],
                self::stringOrNull($entry['name'] ?? null),
                self::stringOrNull($entry['type'] ?? null),
                (int) ($entry['size'] ?? 0),
                (int) ($entry['error'] ?? UPLOAD_ERR_OK),
            );
        }

        return $normalized;
    }

    public function isValid(): bool
    {
        return $this->error === UPLOAD_ERR_OK && $this->temporaryPath !== '';
    }

    public function errorMessage(): string
    {
        return match ($this->error) {
            UPLOAD_ERR_OK => '',
            UPLOAD_ERR_INI_SIZE => 'El archivo excede upload_max_filesize de PHP.',
            UPLOAD_ERR_FORM_SIZE => 'El archivo excede el limite declarado en el formulario.',
            UPLOAD_ERR_PARTIAL => 'El archivo se subio parcialmente.',
            UPLOAD_ERR_NO_FILE => 'No se recibio ningun archivo.',
            UPLOAD_ERR_NO_TMP_DIR => 'Falta el directorio temporal en el servidor.',
            UPLOAD_ERR_CANT_WRITE => 'No se pudo escribir el archivo en disco.',
            UPLOAD_ERR_EXTENSION => 'Una extension de PHP detuvo la subida.',
            default => sprintf('Error de subida desconocido (codigo %d).', $this->error),
        };
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
