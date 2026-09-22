<?php

declare(strict_types=1);

namespace Jocotoco\Support;

interface Clock
{
    /** Marca de tiempo Unix en segundos (UTC). */
    public function now(): int;

    /** Marca de tiempo en milisegundos, usada para generar identificadores ULID. */
    public function nowMilliseconds(): int;

    /** Fecha y hora actual en formato ISO 8601 (UTC). */
    public function nowIso8601(): string;
}
