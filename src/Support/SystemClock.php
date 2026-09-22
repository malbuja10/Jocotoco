<?php

declare(strict_types=1);

namespace Jocotoco\Support;

final class SystemClock implements Clock
{
    public function now(): int
    {
        return time();
    }

    public function nowMilliseconds(): int
    {
        return (int) round(microtime(true) * 1000);
    }

    public function nowIso8601(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }
}
