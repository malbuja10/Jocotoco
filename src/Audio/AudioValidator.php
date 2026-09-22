<?php

declare(strict_types=1);

namespace Jocotoco\Audio;

use DateTimeImmutable;
use Exception;
use Jocotoco\Config\Config;
use Jocotoco\Exception\HttpException;

/**
 * Validacion del archivo de audio recibido y de sus metadatos.
 */
final class AudioValidator
{
    public function __construct(
        private readonly Config $config,
        private readonly AudioSniffer $sniffer,
    ) {
    }

    public function maxBytes(): int
    {
        return $this->config->int('max_upload_bytes');
    }

    public function minBytes(): int
    {
        return $this->config->int('min_upload_bytes');
    }

    /** @return list<string> */
    public function allowedFormats(): array
    {
        $configured = $this->config->list('allowed_formats');

        return $configured === [] ? AudioFormat::names() : $configured;
    }

    /**
     * Comprueba tamano, bytes magicos y coherencia con el Content-Type
     * declarado. Devuelve el formato detectado.
     */
    public function validateFile(string $path, ?string $declaredMediaType = null): AudioFormat
    {
        $size = @filesize($path);
        if ($size === false) {
            throw HttpException::serverError('No se pudo leer el archivo recibido.');
        }

        if ($size < $this->minBytes()) {
            throw HttpException::unprocessable(
                sprintf('El archivo de audio es demasiado pequeno (%d bytes; minimo %d).', $size, $this->minBytes()),
                ['tamano_bytes' => $size, 'minimo_bytes' => $this->minBytes()],
            );
        }

        if ($size > $this->maxBytes()) {
            throw HttpException::payloadTooLarge(
                sprintf('El archivo de audio supera el limite de %d bytes.', $this->maxBytes()),
                ['tamano_bytes' => $size, 'maximo_bytes' => $this->maxBytes()],
            );
        }

        $format = $this->sniffer->sniffFile($path);
        if ($format === null) {
            throw HttpException::unsupportedMediaType(
                'El contenido no corresponde a un formato de audio reconocido.',
                ['formatos_permitidos' => $this->allowedFormats()],
            );
        }

        if (!in_array($format->name, $this->allowedFormats(), true)) {
            throw HttpException::unsupportedMediaType(
                sprintf('El formato "%s" no esta habilitado en este API.', $format->name),
                ['formato_detectado' => $format->name, 'formatos_permitidos' => $this->allowedFormats()],
            );
        }

        if ($declaredMediaType !== null && $declaredMediaType !== '' && !$format->acceptsMediaType($declaredMediaType)) {
            throw HttpException::unsupportedMediaType(sprintf(
                'El Content-Type declarado ("%s") no coincide con el contenido detectado ("%s").',
                $declaredMediaType,
                $format->canonicalMediaType,
            ));
        }

        return $format;
    }

    /**
     * Verifica la integridad contra la suma declarada por el dispositivo.
     * step-cli/curl no garantizan que la transferencia fue completa, por lo
     * que la Raspberry envia el sha256 en una cabecera.
     */
    public function verifyChecksum(string $path, string $declaredSha256): string
    {
        $actual = hash_file('sha256', $path);
        if ($actual === false) {
            throw HttpException::serverError('No se pudo calcular el hash del archivo recibido.');
        }

        $declared = strtolower(trim($declaredSha256));
        if ($declared === '') {
            return $actual;
        }

        if (preg_match('/^[a-f0-9]{64}$/', $declared) !== 1) {
            throw HttpException::badRequest('La cabecera X-Audio-SHA256 debe ser un hash sha256 en hexadecimal.');
        }

        if (!hash_equals($declared, $actual)) {
            throw HttpException::unprocessable(
                'El hash del archivo recibido no coincide con X-Audio-SHA256; reintente el envio.',
                ['sha256_declarado' => $declared, 'sha256_calculado' => $actual],
            );
        }

        return $actual;
    }

    /**
     * Normaliza los metadatos opcionales enviados por el dispositivo.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function normalizeMetadata(array $input): array
    {
        $metadata = [];

        foreach (['sitio', 'estacion', 'nota', 'especie'] as $key) {
            $value = $input[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $metadata[$key] = mb_substr(trim($value), 0, 255);
            }
        }

        foreach (['duracion_segundos', 'frecuencia_muestreo', 'canales', 'ganancia_db'] as $key) {
            $value = $input[$key] ?? null;
            if ($value === null || $value === '') {
                continue;
            }

            if (!is_numeric($value)) {
                throw HttpException::badRequest(sprintf('El metadato "%s" debe ser numerico.', $key));
            }

            $metadata[$key] = 0 + $value;
        }

        foreach (['latitud' => 90.0, 'longitud' => 180.0] as $key => $limit) {
            $value = $input[$key] ?? null;
            if ($value === null || $value === '') {
                continue;
            }

            if (!is_numeric($value) || abs((float) $value) > $limit) {
                throw HttpException::badRequest(sprintf('El metadato "%s" esta fuera de rango.', $key));
            }

            $metadata[$key] = (float) $value;
        }

        return $metadata;
    }

    /**
     * Acepta una fecha de grabacion en ISO 8601 y la normaliza a UTC.
     */
    public function normalizeRecordedAt(mixed $value, int $now): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            $date = new DateTimeImmutable(trim($value));
        } catch (Exception) {
            throw HttpException::badRequest('El campo "grabado_en" debe usar formato ISO 8601 (ej. 2026-09-22T05:30:00Z).');
        }

        $timestamp = $date->getTimestamp();
        $tolerance = $this->config->int('recorded_at_future_tolerance_seconds');
        if ($timestamp > $now + $tolerance) {
            throw HttpException::badRequest('El campo "grabado_en" no puede estar en el futuro.');
        }

        return gmdate('Y-m-d\TH:i:s\Z', $timestamp);
    }
}
