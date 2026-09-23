<?php

declare(strict_types=1);

namespace Jocotoco\Enrollment;

use Jocotoco\Storage\Database;
use PDO;

/**
 * Estado de las inscripciones y bitacora de intentos.
 */
final class EnrollmentRepository
{
    public function __construct(private readonly Database $database)
    {
    }

    /** @return array<string,mixed>|null */
    public function find(string $deviceId): ?array
    {
        $statement = $this->database->connection()->prepare('SELECT * FROM enrollments WHERE device_id = :id');
        $statement->execute([':id' => $deviceId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public function registerIssued(string $deviceId, string $ip, string $timestamp): void
    {
        $statement = $this->database->connection()->prepare(
            'INSERT INTO enrollments (device_id, tokens_issued, first_seen_at, last_seen_at, last_ip)
             VALUES (:id, 1, :now, :now, :ip)
             ON CONFLICT(device_id) DO UPDATE SET
                tokens_issued = tokens_issued + 1,
                last_seen_at = :now,
                last_ip = :ip',
        );
        $statement->execute([':id' => $deviceId, ':now' => $timestamp, ':ip' => $ip]);
    }

    public function logAttempt(?string $deviceId, string $ip, string $result, string $detail, string $timestamp): void
    {
        $statement = $this->database->connection()->prepare(
            'INSERT INTO enrollment_attempts (device_id, ip, result, detail, created_at)
             VALUES (:id, :ip, :result, :detail, :now)',
        );
        $statement->execute([
            ':id' => $deviceId,
            ':ip' => $ip,
            ':result' => $result,
            ':detail' => mb_substr($detail, 0, 500),
            ':now' => $timestamp,
        ]);
    }

    /** Intentos fallidos desde una IP en los ultimos $segundos. */
    public function failedAttemptsFrom(string $ip, string $desde): int
    {
        $statement = $this->database->connection()->prepare(
            "SELECT COUNT(*) FROM enrollment_attempts
             WHERE ip = :ip AND result != 'emitido' AND created_at >= :desde",
        );
        $statement->execute([':ip' => $ip, ':desde' => $desde]);

        return (int) $statement->fetchColumn();
    }

    public function block(string $deviceId, string $motivo, string $timestamp): void
    {
        $statement = $this->database->connection()->prepare(
            'INSERT INTO enrollments (device_id, tokens_issued, first_seen_at, last_seen_at, blocked, notes)
             VALUES (:id, 0, :now, :now, 1, :motivo)
             ON CONFLICT(device_id) DO UPDATE SET blocked = 1, notes = :motivo, last_seen_at = :now',
        );
        $statement->execute([':id' => $deviceId, ':now' => $timestamp, ':motivo' => $motivo]);
    }

    /** @return list<array<string,mixed>> */
    public function listAll(int $limit = 100): array
    {
        $statement = $this->database->connection()->prepare(
            'SELECT * FROM enrollments ORDER BY last_seen_at DESC LIMIT :limit',
        );
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
}
