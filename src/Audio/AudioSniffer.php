<?php

declare(strict_types=1);

namespace Jocotoco\Audio;

/**
 * Detecta el formato de audio leyendo la cabecera del archivo.
 *
 * No se confia en la extension ni en el Content-Type declarado por el
 * dispositivo: un archivo se acepta solo si sus bytes magicos corresponden
 * a un contenedor de audio conocido.
 */
final class AudioSniffer
{
    public const HEADER_BYTES = 64;

    public function sniffFile(string $path): ?AudioFormat
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return null;
        }

        $header = (string) fread($handle, self::HEADER_BYTES);
        fclose($handle);

        return $this->sniff($header);
    }

    public function sniff(string $header): ?AudioFormat
    {
        if (strlen($header) < 12) {
            return null;
        }

        if (str_starts_with($header, 'RIFF') && substr($header, 8, 4) === 'WAVE') {
            return AudioFormat::byName('wav');
        }

        if (str_starts_with($header, 'fLaC')) {
            return AudioFormat::byName('flac');
        }

        if (str_starts_with($header, 'OggS')) {
            return str_contains($header, 'OpusHead')
                ? AudioFormat::byName('opus')
                : AudioFormat::byName('ogg');
        }

        if (substr($header, 4, 4) === 'ftyp') {
            $brand = strtolower(substr($header, 8, 4));
            if (in_array($brand, ['m4a ', 'mp42', 'mp41', 'isom', 'dash', 'm4b '], true)) {
                return AudioFormat::byName('m4a');
            }
        }

        if (str_starts_with($header, 'ID3')) {
            return AudioFormat::byName('mp3');
        }

        $first = ord($header[0]);
        $second = ord($header[1]);

        if ($first === 0xFF && ($second & 0xE0) === 0xE0) {
            // Bits 2-1 del segundo byte indican la capa MPEG; 00 es reservado
            // en MPEG y corresponde a ADTS (AAC).
            $layer = ($second >> 1) & 0x03;

            return $layer === 0
                ? AudioFormat::byName('aac')
                : AudioFormat::byName('mp3');
        }

        return null;
    }
}
