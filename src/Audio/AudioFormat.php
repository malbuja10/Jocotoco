<?php

declare(strict_types=1);

namespace Jocotoco\Audio;

/**
 * Formato de audio aceptado por el API.
 */
final class AudioFormat
{
    /** @param list<string> $mediaTypes */
    private function __construct(
        public readonly string $name,
        public readonly string $extension,
        public readonly string $canonicalMediaType,
        public readonly array $mediaTypes,
    ) {
    }

    /** @return array<string,self> */
    public static function catalog(): array
    {
        static $catalog = null;

        if ($catalog === null) {
            $catalog = [
                'wav' => new self('wav', 'wav', 'audio/wav', ['audio/wav', 'audio/wave', 'audio/x-wav', 'audio/vnd.wave']),
                'flac' => new self('flac', 'flac', 'audio/flac', ['audio/flac', 'audio/x-flac']),
                'opus' => new self('opus', 'opus', 'audio/ogg; codecs=opus', ['audio/opus', 'audio/ogg', 'application/ogg']),
                'ogg' => new self('ogg', 'ogg', 'audio/ogg', ['audio/ogg', 'application/ogg', 'audio/vorbis']),
                'mp3' => new self('mp3', 'mp3', 'audio/mpeg', ['audio/mpeg', 'audio/mp3', 'audio/mpeg3', 'audio/x-mpeg-3']),
                'm4a' => new self('m4a', 'm4a', 'audio/mp4', ['audio/mp4', 'audio/m4a', 'audio/x-m4a']),
                'aac' => new self('aac', 'aac', 'audio/aac', ['audio/aac', 'audio/aacp']),
            ];
        }

        return $catalog;
    }

    public static function byName(string $name): ?self
    {
        return self::catalog()[strtolower($name)] ?? null;
    }

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(self::catalog());
    }

    public function acceptsMediaType(string $mediaType): bool
    {
        $mediaType = strtolower(trim($mediaType));
        if ($mediaType === '' || $mediaType === 'application/octet-stream') {
            return true;
        }

        return in_array($mediaType, $this->mediaTypes, true);
    }
}
