<?php

declare(strict_types=1);

namespace Jocotoco\Tests\Support;

/**
 * Genera archivos de audio sinteticos con cabeceras validas.
 */
final class AudioFactory
{
    /** WAV PCM 16 bit mono con tono senoidal. */
    public static function wav(int $samples = 4000, int $sampleRate = 16000): string
    {
        $data = '';
        for ($i = 0; $i < $samples; $i++) {
            $value = (int) (sin($i / 12) * 12000);
            $data .= pack('v', $value < 0 ? $value + 65536 : $value);
        }

        $dataSize = strlen($data);

        return 'RIFF'
            . pack('V', 36 + $dataSize)
            . 'WAVE'
            . 'fmt ' . pack('V', 16) . pack('vvVVvv', 1, 1, $sampleRate, $sampleRate * 2, 2, 16)
            . 'data' . pack('V', $dataSize)
            . $data;
    }

    public static function flac(int $padding = 4096): string
    {
        return 'fLaC' . "\x00\x00\x00\x22" . str_repeat("\x00", 34) . random_bytes($padding);
    }

    public static function oggOpus(int $padding = 4096): string
    {
        return 'OggS' . str_repeat("\x00", 22) . 'OpusHead' . "\x01\x01" . random_bytes($padding);
    }

    public static function mp3(int $padding = 4096): string
    {
        return 'ID3' . "\x03\x00\x00\x00\x00\x00\x00" . "\xFF\xFB\x90\x00" . random_bytes($padding);
    }

    /** Contenido que no es audio (debe rechazarse). */
    public static function notAudio(int $bytes = 4096): string
    {
        return "%PDF-1.7\n" . str_repeat('a', $bytes);
    }

    public static function writeTo(string $directory, string $filename, string $contents): string
    {
        if (!is_dir($directory)) {
            mkdir($directory, 0o700, true);
        }

        $path = rtrim($directory, '/') . '/' . $filename;
        file_put_contents($path, $contents);

        return $path;
    }
}
