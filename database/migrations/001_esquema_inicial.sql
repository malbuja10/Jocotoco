-- Esquema inicial del API de audio Jocotoco.

CREATE TABLE IF NOT EXISTS recordings (
    id                TEXT PRIMARY KEY,
    device_id         TEXT NOT NULL,
    cert_fingerprint  TEXT NOT NULL,
    format            TEXT NOT NULL,
    media_type        TEXT NOT NULL,
    size_bytes        INTEGER NOT NULL,
    sha256            TEXT NOT NULL,
    relative_path     TEXT NOT NULL,
    original_filename TEXT,
    recorded_at       TEXT,
    received_at       TEXT NOT NULL,
    metadata          TEXT NOT NULL DEFAULT '{}'
);

-- Un mismo dispositivo no puede almacenar dos veces el mismo audio:
-- permite que la Raspberry reintente un envio sin duplicar grabaciones.
CREATE UNIQUE INDEX IF NOT EXISTS ux_recordings_dispositivo_sha256
    ON recordings (device_id, sha256);

CREATE INDEX IF NOT EXISTS ix_recordings_recibido
    ON recordings (received_at DESC);

CREATE INDEX IF NOT EXISTS ix_recordings_dispositivo_recibido
    ON recordings (device_id, received_at DESC);

CREATE TABLE IF NOT EXISTS devices (
    id                    TEXT PRIMARY KEY,
    first_seen_at         TEXT NOT NULL,
    last_seen_at          TEXT NOT NULL,
    last_cert_fingerprint TEXT NOT NULL,
    recordings_count      INTEGER NOT NULL DEFAULT 0,
    bytes_stored          INTEGER NOT NULL DEFAULT 0
);
