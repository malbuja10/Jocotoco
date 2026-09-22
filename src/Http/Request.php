<?php

declare(strict_types=1);

namespace Jocotoco\Http;

/**
 * Representacion inmutable y testeable de una peticion HTTP.
 *
 * No se usa PSR-7 para no arrastrar dependencias de runtime: el API se
 * despliega sobre php-fpm en Ubuntu y solo necesita leer $_SERVER, la query
 * string, las cabeceras y el cuerpo (como stream, para audio de varios MB).
 */
final class Request
{
    /** @var array<string,string> cabeceras normalizadas en minusculas */
    private array $headers;

    /**
     * @param array<string,string|list<string>> $query
     * @param array<string,string> $headers
     * @param array<string,mixed> $server
     * @param list<UploadedFile> $files
     * @param resource|null $body
     * @param array<string,mixed> $parsedBody campos de un multipart/form-data ($_POST)
     */
    public function __construct(
        private readonly string $method,
        private readonly string $path,
        array $headers = [],
        private readonly array $query = [],
        private readonly array $server = [],
        private readonly array $files = [],
        private $body = null,
        private readonly array $parsedBody = [],
    ) {
        $normalized = [];
        foreach ($headers as $name => $value) {
            $normalized[strtolower($name)] = $value;
        }
        $this->headers = $normalized;
    }

    /**
     * @param array<string,mixed> $server
     * @param array<string,mixed> $filesSuperglobal el contenido de $_FILES
     * @param array<string,mixed> $parsedBody el contenido de $_POST
     */
    public static function fromGlobals(
        array $server,
        array $filesSuperglobal = [],
        array $parsedBody = [],
        ?string $bodyPath = null,
    ): self
    {
        $method = strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET'));
        $uri = (string) ($server['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';

        $query = [];
        $queryString = (string) ($server['QUERY_STRING'] ?? '');
        if ($queryString !== '') {
            parse_str($queryString, $query);
        }

        $body = fopen($bodyPath ?? 'php://input', 'rb');

        /** @var array<string,string|list<string>> $query */
        return new self(
            $method,
            self::normalizePath($path),
            self::extractHeaders($server),
            $query,
            $server,
            UploadedFile::fromFilesSuperglobal($filesSuperglobal),
            $body === false ? null : $body,
            $parsedBody,
        );
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function header(string $name, string $default = ''): string
    {
        $value = $this->headers[strtolower($name)] ?? $default;

        return is_array($value) ? (string) ($value[0] ?? $default) : (string) $value;
    }

    public function hasHeader(string $name): bool
    {
        return isset($this->headers[strtolower($name)]);
    }

    /** @return array<string,string> */
    public function headers(): array
    {
        return $this->headers;
    }

    public function contentType(): string
    {
        $value = $this->header('content-type');
        $semicolon = strpos($value, ';');

        return strtolower(trim($semicolon === false ? $value : substr($value, 0, $semicolon)));
    }

    public function contentLength(): ?int
    {
        $raw = $this->header('content-length');

        return preg_match('/^\d+$/', $raw) === 1 ? (int) $raw : null;
    }

    public function query(string $name, ?string $default = null): ?string
    {
        $value = $this->query[$name] ?? $default;
        if (is_array($value)) {
            $value = $value[0] ?? $default;
        }

        return $value === null ? null : (string) $value;
    }

    public function queryInt(string $name, int $default, int $min, int $max): int
    {
        $raw = $this->query($name);
        if ($raw === null || preg_match('/^-?\d+$/', $raw) !== 1) {
            return $default;
        }

        return max($min, min($max, (int) $raw));
    }

    public function server(string $name, string $default = ''): string
    {
        $value = $this->server[$name] ?? $default;

        return is_scalar($value) ? (string) $value : $default;
    }

    /** @return array<string,mixed> */
    public function serverParams(): array
    {
        return $this->server;
    }

    /** @return list<UploadedFile> */
    public function files(): array
    {
        return $this->files;
    }

    public function file(string $field): ?UploadedFile
    {
        foreach ($this->files as $file) {
            if ($file->field === $field) {
                return $file;
            }
        }

        return null;
    }

    /** @return array<string,mixed> */
    public function parsedBody(): array
    {
        return $this->parsedBody;
    }

    /**
     * Valor de un campo del cuerpo (multipart) o, en su defecto, de la query string.
     */
    public function input(string $name, ?string $default = null): ?string
    {
        $value = $this->parsedBody[$name] ?? null;
        if (is_scalar($value) && (string) $value !== '') {
            return (string) $value;
        }

        return $this->query($name, $default);
    }

    /** @return resource|null */
    public function body()
    {
        return $this->body;
    }

    /** @return array<string,string> */
    private static function extractHeaders(array $server): array
    {
        $headers = [];
        foreach ($server as $key => $value) {
            if (!is_string($key) || !is_scalar($value)) {
                continue;
            }

            if (str_starts_with($key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = (string) $value;
                continue;
            }

            if ($key === 'CONTENT_TYPE' || $key === 'CONTENT_LENGTH') {
                $headers[strtolower(str_replace('_', '-', $key))] = (string) $value;
            }
        }

        return $headers;
    }

    private static function normalizePath(string $path): string
    {
        $decoded = rawurldecode($path);
        $trimmed = rtrim($decoded, '/');

        return $trimmed === '' ? '/' : $trimmed;
    }
}
