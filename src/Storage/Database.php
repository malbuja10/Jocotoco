<?php

declare(strict_types=1);

namespace Jocotoco\Storage;

use Jocotoco\Exception\StorageException;
use PDO;
use PDOException;

/**
 * Conexion a la base de datos y aplicacion de migraciones.
 *
 * En produccion se usa PostgreSQL (driver "pgsql"); SQLite en modo WAL se
 * conserva para las pruebas y el desarrollo local. Las consultas de los
 * repositorios son SQL comun a ambos (ON CONFLICT, LIMIT/OFFSET); lo que
 * cambia es el DDL, por eso cada motor tiene su carpeta de migraciones:
 *
 *   database/migrations/         SQLite
 *   database/migrations-pgsql/   PostgreSQL
 *
 * Las versiones se llaman igual en las dos, de modo que schema_migrations
 * dice lo mismo sea cual sea el motor.
 */
final class Database
{
    public const SQLITE = 'sqlite';
    public const PGSQL = 'pgsql';

    private ?PDO $connection = null;

    /**
     * @param string $path ruta del archivo SQLite (driver sqlite) o DSN completo de PDO (driver pgsql)
     */
    public function __construct(
        private readonly string $path,
        private readonly string $migrationsDirectory,
        private readonly int $busyTimeoutMs = 5000,
        private readonly string $driver = self::SQLITE,
        private readonly ?string $user = null,
        private readonly ?string $password = null,
    ) {
        if (!in_array($driver, [self::SQLITE, self::PGSQL], true)) {
            throw new StorageException(sprintf('Motor de base de datos no soportado: "%s" (use sqlite o pgsql).', $driver));
        }
    }

    public function driver(): string
    {
        return $this->driver;
    }

    public function isPostgres(): bool
    {
        return $this->driver === self::PGSQL;
    }

    public function connection(): PDO
    {
        if ($this->connection instanceof PDO) {
            return $this->connection;
        }

        if ($this->driver === self::PGSQL) {
            return $this->connection = $this->connectPostgres();
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

    private function connectPostgres(): PDO
    {
        $dsn = str_starts_with($this->path, 'pgsql:') ? $this->path : 'pgsql:' . $this->path;

        try {
            $pdo = new PDO($dsn, $this->user ?: null, $this->password ?: null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
            ]);
        } catch (PDOException $exception) {
            // El mensaje de PDO puede incluir el DSN; nunca la contrasena.
            throw new StorageException('No se pudo conectar a PostgreSQL: ' . $exception->getMessage(), 0, $exception);
        }

        $pdo->exec("SET TIME ZONE 'UTC'");
        $pdo->exec(sprintf('SET statement_timeout = %d', max(1000, $this->busyTimeoutMs * 6)));
        $pdo->exec(sprintf('SET lock_timeout = %d', $this->busyTimeoutMs));

        return $pdo;
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

    /** Libera la conexion (PostgreSQL limita las conexiones simultaneas). */
    public function close(): void
    {
        $this->connection = null;
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
