-- Lecturas de corriente (amperios), POST /api/telemetry/current.
-- Mismo tratamiento que el voltaje: deduplicacion por dispositivo, instante y valor.

CREATE TABLE IF NOT EXISTS current_readings (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    device_id   TEXT NOT NULL,
    measured_at INTEGER NOT NULL,           -- epoch en segundos
    value       REAL NOT NULL,              -- amperios
    received_at TEXT NOT NULL
);

CREATE UNIQUE INDEX IF NOT EXISTS ux_corriente_lectura
    ON current_readings (device_id, measured_at, value);

-- Las consultas del panel filtran por dispositivo y rango de tiempo.
CREATE INDEX IF NOT EXISTS ix_voltaje_dispositivo_medido
    ON voltage_readings (device_id, measured_at DESC);

CREATE INDEX IF NOT EXISTS ix_telemetria_dispositivo_medido
    ON device_telemetry (device_id, measured_at DESC);

CREATE INDEX IF NOT EXISTS ix_eventos_dispositivo_recibido
    ON detection_events (device_id, received_at DESC);
