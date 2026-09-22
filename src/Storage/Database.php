<?php

declare(strict_types=1);

namespace Jocotoco\Storage;

use Jocotoco\Exception\StorageException;
use PDO;
use PDOException;

/**
 * Conexion SQLite y aplicacion de migraciones.
 *
 * SQLite en modo WAL es suficiente para este caso de uso (decenas de
 * dispositivos escribiendo metadatos pequenos); el audio vive en disco.
 */
final class Database
{
    private ?PDO $connection = null;

    public function __construct(
        private readonly string $path,
        private readonly string $migrationsDirectory,
        private readonly int $busyTimeoutMs = 5000,
    ) {
    }

    public function connection(): PDO
    {
        if ($this->connection instanceof PDO) {
            return $this->connection;
        }

        if ($this->path !== ':memory:') {
            $directory = dirname($this->path);
            if (!is_dir($directory) && !@mkdir($directory, 0o750, true) && !is_dir($directory)) {
                throw new StorageException(sprintf('No se pudo crear el directorio de la base de datos: %s', $directory));
            }
        }

        try {
            $pdo = new PDO('sqlite:' . $this->path, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $exception) {
            throw new StorageException('No se pudo abrir la base de datos: ' . $exception->getMessage(), 0, $exception);
        }

        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA synchronous = NORMAL');
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec(sprintf('PRAGMA busy_timeout = %d', $this->busyTimeoutMs));

        return $this->connection = $pdo;
    }

    /**
     * Aplica las migraciones pendientes y devuelve las versiones aplicadas.
     *
     * @return list<string>
     */
    public function migrate(): array
    {
        $pdo = $this->connection();
        $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (version TEXT PRIMARY KEY, applied_at TEXT NOT NULL)');

        $applied = $pdo->query('SELECT version FROM schema_migrations')?->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $applied = array_map(strval(...), $applied);

        $files = glob($this->migrationsDirectory . '/*.sql') ?: [];
        sort($files);

        $executed = [];
        foreach ($files as $file) {
            $version = basename($file, '.sql');
            if (in_array($version, $applied, true)) {
                continue;
            }

            $sql = file_get_contents($file);
            if ($sql === false) {
                throw new StorageException(sprintf('No se pudo leer la migracion %s.', $file));
            }

            $pdo->beginTransaction();
            try {
                $pdo->exec($sql);
                $statement = $pdo->prepare('INSERT INTO schema_migrations (version, applied_at) VALUES (:version, :applied_at)');
                $statement->execute([':version' => $version, ':applied_at' => gmdate('Y-m-d\TH:i:s\Z')]);
                $pdo->commit();
            } catch (PDOException $exception) {
                $pdo->rollBack();

                throw new StorageException(sprintf('Fallo la migracion %s: %s', $version, $exception->getMessage()), 0, $exception);
            }

            $executed[] = $version;
        }

        return $executed;
    }

    public function isHealthy(): bool
    {
        try {
            $this->connection()->query('SELECT 1');

            return true;
        } catch (PDOException|StorageException) {
            return false;
        }
    }
}
