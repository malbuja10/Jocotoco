<?php

declare(strict_types=1);

namespace Jocotoco\Storage;

use Jocotoco\Audio\AudioFormat;
use Jocotoco\Exception\StorageException;
use Jocotoco\Support\Clock;

/**
 * Almacenamiento de los archivos de audio en disco.
 *
 * Estructura: <raiz>/<dispositivo>/<aaaa>/<mm>/<dd>/<id>.<ext>
 * La escritura es atomica (archivo temporal + rename) para que un envio
 * interrumpido nunca deje un archivo a medias visible en el arbol.
 */
final class AudioStorage
{
    public function __construct(
        private readonly string $rootDirectory,
        private readonly Clock $clock,
        private readonly int $directoryMode = 0o750,
        private readonly int $fileMode = 0o640,
    ) {
    }

    public function rootDirectory(): string
    {
        return $this->rootDirectory;
    }

    /**
     * Mueve (o copia) el archivo recibido a su ubicacion definitiva y
     * devuelve la ruta relativa a la raiz de almacenamiento.
     */
    public function store(string $sourcePath, string $deviceId, string $recordingId, AudioFormat $format, bool $move = true): string
    {
        $relativeDirectory = sprintf(
            '%s/%s',
            self::sanitizeSegment($deviceId),
            gmdate('Y/m/d', $this->clock->now()),
        );

        $absoluteDirectory = $this->rootDirectory . '/' . $relativeDirectory;
        $this->ensureDirectory($absoluteDirectory);

        $relativePath = $relativeDirectory . '/' . $recordingId . '.' . $format->extension;
        $absolutePath = $this->rootDirectory . '/' . $relativePath;
        $temporaryPath = $absolutePath . '.parcial';

        $copied = $move && is_uploaded_file($sourcePath)
            ? move_uploaded_file($sourcePath, $temporaryPath)
            : copy($sourcePath, $temporaryPath);

        if ($copied === false) {
            @unlink($temporaryPath);

            throw new StorageException(sprintf('No se pudo escribir el audio en %s.', $temporaryPath));
        }

        if (!@chmod($temporaryPath, $this->fileMode)) {
            // Permisos heredados del directorio: no es fatal, solo se registra.
        }

        if (!@rename($temporaryPath, $absolutePath)) {
            @unlink($temporaryPath);

            throw new StorageException(sprintf('No se pudo publicar el audio en %s.', $absolutePath));
        }

        if ($move && !is_uploaded_file($sourcePath)) {
            @unlink($sourcePath);
        }

        return $relativePath;
    }

    public function absolutePath(string $relativePath): string
    {
        $candidate = $this->rootDirectory . '/' . ltrim($relativePath, '/');
        $real = realpath($candidate);
        $root = realpath($this->rootDirectory);

        if ($real === false || $root === false || !str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
            throw new StorageException(sprintf('Ruta de audio fuera del directorio permitido: %s', $relativePath));
        }

        return $real;
    }

    public function exists(string $relativePath): bool
    {
        return is_file($this->rootDirectory . '/' . ltrim($relativePath, '/'));
    }

    public function delete(string $relativePath): bool
    {
        if (!$this->exists($relativePath)) {
            return false;
        }

        return @unlink($this->absolutePath($relativePath));
    }

    public function freeBytes(): ?int
    {
        $free = @disk_free_space($this->rootDirectory);

        return $free === false ? null : (int) $free;
    }

    public function ensureDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        if (!@mkdir($directory, $this->directoryMode, true) && !is_dir($directory)) {
            throw new StorageException(sprintf('No se pudo crear el directorio %s.', $directory));
        }
    }

    /**
     * Impide que un nombre tomado del certificado (SAN/CN) escape del arbol
     * de almacenamiento o cree rutas inesperadas.
     */
    public static function sanitizeSegment(string $segment): string
    {
        $clean = preg_replace('/[^A-Za-z0-9._-]/', '_', $segment) ?? '';
        // Ningun segmento puede contener ".." ni empezar o terminar con separadores.
        $clean = preg_replace('/\.{2,}/', '.', $clean) ?? '';
        $clean = preg_replace('/_{2,}/', '_', $clean) ?? '';
        $clean = trim($clean, '._-');

        return $clean === '' ? 'desconocido' : mb_substr($clean, 0, 96);
    }
}
