<?php

declare(strict_types=1);

namespace Jocotoco\Exception;

use RuntimeException;
use Throwable;

/**
 * Error que se puede traducir directamente a una respuesta HTTP
 * con formato "application/problem+json" (RFC 7807).
 */
class HttpException extends RuntimeException
{
    /** @param array<string,mixed> $extra */
    public function __construct(
        private readonly int $status,
        string $detail,
        private readonly string $title,
        private readonly string $type = 'about:blank',
        private readonly array $extra = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($detail, $status, $previous);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function type(): string
    {
        return $this->type;
    }

    /** @return array<string,mixed> */
    public function extra(): array
    {
        return $this->extra;
    }

    /** @param array<string,mixed> $extra */
    public static function badRequest(string $detail, array $extra = []): self
    {
        return new self(400, $detail, 'Solicitud invalida', 'https://jocotoco.api/problems/solicitud-invalida', $extra);
    }

    public static function unauthorized(string $detail): self
    {
        return new self(401, $detail, 'No autenticado', 'https://jocotoco.api/problems/no-autenticado');
    }

    public static function forbidden(string $detail): self
    {
        return new self(403, $detail, 'Acceso denegado', 'https://jocotoco.api/problems/acceso-denegado');
    }

    public static function notFound(string $detail): self
    {
        return new self(404, $detail, 'Recurso no encontrado', 'https://jocotoco.api/problems/no-encontrado');
    }

    public static function methodNotAllowed(string $detail, array $allowed = []): self
    {
        return new self(405, $detail, 'Metodo no permitido', 'https://jocotoco.api/problems/metodo-no-permitido', ['allow' => $allowed]);
    }

    /** @param array<string,mixed> $extra */
    public static function unsupportedMediaType(string $detail, array $extra = []): self
    {
        return new self(415, $detail, 'Tipo de contenido no soportado', 'https://jocotoco.api/problems/media-type', $extra);
    }

    /** @param array<string,mixed> $extra */
    public static function payloadTooLarge(string $detail, array $extra = []): self
    {
        return new self(413, $detail, 'Carga demasiado grande', 'https://jocotoco.api/problems/carga-grande', $extra);
    }

    /** @param array<string,mixed> $extra */
    public static function unprocessable(string $detail, array $extra = []): self
    {
        return new self(422, $detail, 'Entidad no procesable', 'https://jocotoco.api/problems/entidad-no-procesable', $extra);
    }

    public static function serverError(string $detail, ?Throwable $previous = null): self
    {
        return new self(500, $detail, 'Error interno', 'https://jocotoco.api/problems/error-interno', [], $previous);
    }
}
