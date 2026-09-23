-- Registro de inscripciones (alta automatica de dispositivos).

CREATE TABLE IF NOT EXISTS enrollments (
    device_id     TEXT PRIMARY KEY,
    tokens_issued INTEGER NOT NULL DEFAULT 0,
    first_seen_at TEXT NOT NULL,
    last_seen_at  TEXT NOT NULL,
    last_ip       TEXT,
    blocked       INTEGER NOT NULL DEFAULT 0,
    notes         TEXT
);

-- Intentos, incluidos los rechazados: es el rastro para auditar quien pidio
-- tokens con el secreto de fabrica.
CREATE TABLE IF NOT EXISTS enrollment_attempts (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    device_id  TEXT,
    ip         TEXT,
    result     TEXT NOT NULL,
    detail     TEXT,
    created_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS ix_enrollment_attempts_fecha
    ON enrollment_attempts (created_at DESC);
