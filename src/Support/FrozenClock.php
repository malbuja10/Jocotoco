<?php

declare(strict_types=1);

namespace Jocotoco\Support;

/**
 * Reloj fijo para pruebas.
 */
final class FrozenClock implements Clock
{
    public function __construct(private int $timestamp)
    {
    }

    public function now(): int
    {
        return $this->timestamp;
    }

    public function nowMilliseconds(): int
    {
        return $this->timestamp * 1000;
    }

    public function nowIso8601(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', $this->timestamp);
    }

    public function advance(int $seconds): void
    {
        $this->timestamp += $seconds;
    }
}
