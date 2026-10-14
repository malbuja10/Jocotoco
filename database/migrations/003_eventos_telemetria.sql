-- Eventos de deteccion y telemetria del monitor de campo.
-- Contrato de integracion API | Monitor Jocotoco v1.0 (secciones 3 y 4).

CREATE TABLE IF NOT EXISTS detection_events (
    id                  TEXT PRIMARY KEY,   -- identificador interno (ULID)
    device_id           TEXT NOT NULL,
    event_id            TEXT NOT NULL,      -- identificador generado por el monitor
    threat_type         TEXT,               -- etiqueta del modelo, NO amenaza confirmada
    confidence_score    REAL,
    event_timestamp     INTEGER,            -- epoch declarado por el analizador
    occurred_at         TEXT,               -- el mismo instante en ISO 8601
    duration_sec        REAL,               -- medidos sobre el WAV recibido
    sample_rate         INTEGER,
    channels            INTEGER,
    bit_depth           INTEGER,
    declared_audio_path TEXT,               -- ruta local del dispositivo, solo referencia
    recording_id        TEXT,               -- grabacion almacenada (tabla recordings)
    payload             TEXT NOT NULL,      -- JSON original tal como llego
    received_at         TEXT NOT NULL
);

-- Idempotencia pedida por el contrato: device_id + event_id es la clave
-- logica, de modo que repetir un envio no duplica el evento.
CREATE UNIQUE INDEX IF NOT EXISTS ux_eventos_dispositivo_evento
    ON detection_events (device_id, event_id);

CREATE INDEX IF NOT EXISTS ix_eventos_recibido
    ON detection_events (received_at DESC);

CREATE INDEX IF NOT EXISTS ix_eventos_clase
    ON detection_events (threat_type, received_at DESC);

CREATE TABLE IF NOT EXISTS voltage_readings (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    device_id   TEXT NOT NULL,
    measured_at INTEGER NOT NULL,           -- epoch en segundos
    value       REAL NOT NULL,              -- voltios
    received_at TEXT NOT NULL
);

-- La telemetria no trae identificador por registro: se deduplica por
-- dispositivo + instante, que es la regla acordada en el contrato.
CREATE UNIQUE INDEX IF NOT EXISTS ux_voltaje_dispositivo_instante
    ON voltage_readings (device_id, measured_at);

CREATE TABLE IF NOT EXISTS device_telemetry (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    device_id      TEXT NOT NULL,
    measured_at    INTEGER NOT NULL,        -- epoch en segundos
    cpu_temp       REAL,                    -- grados Celsius
    cpu_usage_pct  REAL,
    ram_usage_pct  REAL,
    disk_free_gb   REAL,
    disk_usage_pct REAL,
    uptime_hours   REAL,
    received_at    TEXT NOT NULL
);

CREATE UNIQUE INDEX IF NOT EXISTS ux_telemetria_dispositivo_instante
    ON device_telemetry (device_id, measured_at);
