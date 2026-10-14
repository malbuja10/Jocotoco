<?php

declare(strict_types=1);

namespace Jocotoco\Audio;

/**
 * Caracteristicas reales de un WAV, leidas de su cabecera.
 */
final class WavInfo
{
    public const PCM = 1;
    public const IEEE_FLOAT = 3;
    public const EXTENSIBLE = 0xFFFE;

    public function __construct(
        public readonly int $canales,
        public readonly int $frecuenciaMuestreo,
        public readonly int $bitsPorMuestra,
        public readonly float $duracionSegundos,
        public readonly int $bytesDeDatos,
        public readonly int $codigoFormato = self::PCM,
    ) {
    }

    public function esPcm(): bool
    {
        return in_array($this->codigoFormato, [self::PCM, self::IEEE_FLOAT, self::EXTENSIBLE], true);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'sample_rate' => $this->frecuenciaMuestreo,
            'channels' => $this->canales,
            'bit_depth' => $this->bitsPorMuestra,
            'duration_sec' => $this->duracionSegundos,
        ];
    }
}
