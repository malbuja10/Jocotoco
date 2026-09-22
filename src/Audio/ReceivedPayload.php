<?php

declare(strict_types=1);

namespace Jocotoco\Audio;

/**
 * Archivo temporal ya materializado en disco junto con lo que el
 * dispositivo declaro sobre el (nombre, tipo y metadatos).
 */
final class ReceivedPayload
{
    /** @param array<string,mixed> $fields */
    public function __construct(
        public readonly string $temporaryPath,
        public readonly ?string $clientFilename,
        public readonly ?string $declaredMediaType,
        public readonly array $fields,
        public readonly bool $temporaryOwned,
    ) {
    }

    public function discard(): void
    {
        if ($this->temporaryOwned && is_file($this->temporaryPath)) {
            @unlink($this->temporaryPath);
        }
    }
}
