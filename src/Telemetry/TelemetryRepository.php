<?php

declare(strict_types=1);

namespace Jocotoco\Telemetry;

use Jocotoco\Storage\Database;
use PDO;

/**
 * Lecturas de voltaje y estado del dispositivo.
 *
 * La telemetria no trae identificador por registro, asi que la clave logica
 * acordada en el contrato es dispositivo + instante: repetir un envio no
 * duplica filas.
 */
final class TelemetryRepository
{
    public function __construct(private readonly Database $database)
    {
    }

    /**
     * @param list<array{measured_at:int,value:float}> $lecturas
     * @return array{guardadas:int,duplicadas:int}
     */
    public function saveVoltage(string $deviceId, array $lecturas, string $receivedAt): array
    {
        $pdo = $this->database->connection();
        $statement = $pdo->prepare(
            'INSERT OR IGNORE INTO voltage_readings (device_id, measured_at, value, received_at)
             VALUES (:device_id, :measured_at, :value, :received_at)',
        );

        $guardadas = 0;
        $pdo->beginTransaction();
        try {
            foreach ($lecturas as $lectura) {
                $statement->execute([
                    ':device_id' => $deviceId,
                    ':measured_at' => $lectura['measured_at'],
                    ':value' => $lectura['value'],
                    ':received_at' => $receivedAt,
                ]);
                $guardadas += $statement->rowCount();
            }
            $pdo->commit();
        } catch (\Throwable $exception) {
            $pdo->rollBack();

            throw $exception;
        }

        return ['guardadas' => $guardadas, 'duplicadas' => count($lecturas) - $guardadas];
    }

    /**
     * @param list<array<string,float|int|null>> $registros
     * @return array{guardadas:int,duplicadas:int}
     */
    public function saveStatus(string $deviceId, array $registros, string $receivedAt): array
    {
        $pdo = $this->database->connection();
        $statement = $pdo->prepare(
            'INSERT OR IGNORE INTO device_telemetry (
                device_id, measured_at, cpu_temp, cpu_usage_pct, ram_usage_pct,
                disk_free_gb, disk_usage_pct, uptime_hours, received_at
            ) VALUES (
                :device_id, :measured_at, :cpu_temp, :cpu_usage_pct, :ram_usage_pct,
                :disk_free_gb, :disk_usage_pct, :uptime_hours, :received_at
            )',
        );

        $guardadas = 0;
        $pdo->beginTransaction();
        try {
            foreach ($registros as $registro) {
                $statement->execute([
                    ':device_id' => $deviceId,
                    ':measured_at' => $registro['measured_at'],
                    ':cpu_temp' => $registro['cpu_temp'] ?? null,
                    ':cpu_usage_pct' => $registro['cpu_usage_pct'] ?? null,
                    ':ram_usage_pct' => $registro['ram_usage_pct'] ?? null,
                    ':disk_free_gb' => $registro['disk_free_gb'] ?? null,
                    ':disk_usage_pct' => $registro['disk_usage_pct'] ?? null,
                    ':uptime_hours' => $registro['uptime_hours'] ?? null,
                    ':received_at' => $receivedAt,
                ]);
                $guardadas += $statement->rowCount();
            }
            $pdo->commit();
        } catch (\Throwable $exception) {
            $pdo->rollBack();

            throw $exception;
        }

        return ['guardadas' => $guardadas, 'duplicadas' => count($registros) - $guardadas];
    }

    /** @return list<array<string,mixed>> */
    public function lastVoltage(string $deviceId, int $limit = 50): array
    {
        $statement = $this->database->connection()->prepare(
            'SELECT measured_at, value, received_at FROM voltage_readings
             WHERE device_id = :device_id ORDER BY measured_at DESC LIMIT :limit',
        );
        $statement->bindValue(':device_id', $deviceId, PDO::PARAM_STR);
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string,mixed>> */
    public function lastStatus(string $deviceId, int $limit = 50): array
    {
        $statement = $this->database->connection()->prepare(
            'SELECT * FROM device_telemetry
             WHERE device_id = :device_id ORDER BY measured_at DESC LIMIT :limit',
        );
        $statement->bindValue(':device_id', $deviceId, PDO::PARAM_STR);
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
}
