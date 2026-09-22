<?php

declare(strict_types=1);

namespace Jocotoco\Http;

/**
 * Respuesta HTTP. El cuerpo puede ser un texto o un archivo en disco
 * (para descargar audio sin cargarlo completo en memoria).
 */
final class Response
{
    /** @param array<string,string> $headers */
    private function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
        public readonly ?string $filePath = null,
    ) {
    }

    /** @param array<string,string> $headers */
    public static function text(int $status, string $body, array $headers = []): self
    {
        return new self($status, array_merge(['Content-Type' => 'text/plain; charset=utf-8'], $headers), $body);
    }

    /** @param array<string,string> $headers */
    public static function json(int $status, mixed $payload, array $headers = []): self
    {
        $encoded = json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
        );

        return new self($status, array_merge(['Content-Type' => 'application/json; charset=utf-8'], $headers), $encoded . "\n");
    }

    /**
     * Respuesta de error con formato RFC 7807.
     *
     * @param array<string,mixed> $extra
     */
    public static function problem(int $status, string $title, string $detail, string $type = 'about:blank', array $extra = []): self
    {
        $payload = array_merge([
            'type' => $type,
            'title' => $title,
            'status' => $status,
            'detail' => $detail,
        ], $extra);

        $encoded = json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
        );

        return new self($status, ['Content-Type' => 'application/problem+json; charset=utf-8'], $encoded . "\n");
    }

    /** @param array<string,string> $headers */
    public static function file(string $path, string $mediaType, string $downloadName, array $headers = []): self
    {
        $size = @filesize($path);

        return new self(200, array_merge([
            'Content-Type' => $mediaType,
            'Content-Length' => (string) ($size === false ? 0 : $size),
            'Content-Disposition' => sprintf('attachment; filename="%s"', str_replace('"', '', $downloadName)),
            'X-Content-Type-Options' => 'nosniff',
        ], $headers), '', $path);
    }

    public static function noContent(): self
    {
        return new self(204, [], '');
    }

    /** @param array<string,string> $headers */
    public function withHeaders(array $headers): self
    {
        return new self($this->status, array_merge($this->headers, $headers), $this->body, $this->filePath);
    }

    /**
     * Envia la respuesta al cliente. `header()`/`echo` se aislan aqui
     * para que el resto del codigo sea testeable sin buffers de salida.
     */
    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                // El codigo se repite en cada cabecera a proposito: PHP
                // convierte la respuesta en 302 al enviar "Location" si no
                // se le indica explicitamente el estado.
                header($name . ': ' . $value, true, $this->status);
            }
        }

        if ($this->filePath !== null) {
            $handle = fopen($this->filePath, 'rb');
            if ($handle !== false) {
                fpassthru($handle);
                fclose($handle);
            }

            return;
        }

        echo $this->body;
    }
}
