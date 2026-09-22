<?php

declare(strict_types=1);

namespace Jocotoco\Support;

/**
 * Registro de eventos en formato JSON por linea (apto para journald/Loki).
 */
final class Logger
{
    /** @param resource|null $stream */
    public function __construct(
        private readonly string $path,
        private readonly Clock $clock,
        private $stream = null,
    ) {
    }

    /** @param array<string,mixed> $context */
    public function info(string $message, array $context = []): void
    {
        $this->write('info', $message, $context);
    }

    /** @param array<string,mixed> $context */
    public function warning(string $message, array $context = []): void
    {
        $this->write('warning', $message, $context);
    }

    /** @param array<string,mixed> $context */
    public function error(string $message, array $context = []): void
    {
        $this->write('error', $message, $context);
    }

    /** @param array<string,mixed> $context */
    private function write(string $level, string $message, array $context): void
    {
        $line = json_encode([
            'ts' => $this->clock->nowIso8601(),
            'nivel' => $level,
            'mensaje' => $message,
            'contexto' => $context,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        if ($line === false) {
            return;
        }

        if ($this->stream !== null) {
            fwrite($this->stream, $line . "\n");

            return;
        }

        if ($this->path === 'stderr') {
            file_put_contents('php://stderr', $line . "\n");

            return;
        }

        $directory = dirname($this->path);
        if (!is_dir($directory)) {
            @mkdir($directory, 0o750, true);
        }

        @file_put_contents($this->path, $line . "\n", FILE_APPEND | LOCK_EX);
    }
}
