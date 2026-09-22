<?php

declare(strict_types=1);

namespace Jocotoco\Tests\Support;

/**
 * Directorio temporal aislado por prueba.
 */
final class TemporaryDirectory
{
    public readonly string $path;

    public function __construct(string $prefix = 'jocotoco-test-')
    {
        $path = sys_get_temp_dir() . '/' . $prefix . bin2hex(random_bytes(6));
        mkdir($path, 0o700, true);
        $this->path = $path;
    }

    public function child(string $relative): string
    {
        return $this->path . '/' . ltrim($relative, '/');
    }

    public function remove(): void
    {
        self::removeRecursively($this->path);
    }

    private static function removeRecursively(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            @unlink($path);

            return;
        }

        if (!is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            self::removeRecursively($path . '/' . $entry);
        }

        @rmdir($path);
    }
}
