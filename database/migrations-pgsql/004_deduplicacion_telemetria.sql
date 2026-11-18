-- La clave de deduplicacion de la telemetria pasa a incluir el contenido.
-- Ver la explicacion en database/migrations/004_deduplicacion_telemetria.sql.

DROP INDEX IF EXISTS ux_voltaje_dispositivo_instante;

CREATE UNIQUE INDEX IF NOT EXISTS ux_voltaje_lectura
    ON voltage_readings (device_id, measured_at, value);

DROP INDEX IF EXISTS ux_telemetria_dispositivo_instante;

-- NULLS NOT DISTINCT (PostgreSQL 15+) hace lo que en SQLite logra el
-- COALESCE: un reenvio con metricas vacias no se duplica.
CREATE UNIQUE INDEX IF NOT EXISTS ux_telemetria_registro
    ON device_telemetry (
        device_id, measured_at, cpu_temp, cpu_usage_pct, ram_usage_pct,
        disk_free_gb, disk_usage_pct, uptime_hours
    ) NULLS NOT DISTINCT;
