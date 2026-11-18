-- Esquema inicial del API de audio Jocotoco (PostgreSQL).
-- Equivalente a database/migrations/001_esquema_inicial.sql (SQLite).
-- Las fechas se guardan como texto ISO 8601 en UTC, igual que en SQLite:
-- el codigo las compara como cadenas y asi se comporta igual en los dos motores.

CREATE TABLE IF NOT EXISTS recordings (
    id                TEXT PRIMARY KEY,
    device_id         TEXT NOT NULL,
    cert_fingerprint  TEXT NOT NULL,
    format            TEXT NOT NULL,
    media_type        TEXT NOT NULL,
    size_bytes        BIGINT NOT NULL,
    sha256            TEXT NOT NULL,
    relative_path     TEXT NOT NULL,
    original_filename TEXT,
    recorded_at       TEXT,
    received_at       TEXT NOT NULL,
    metadata          TEXT NOT NULL DEFAULT '{}'
);

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
    bytes_stored          BIGINT NOT NULL DEFAULT 0
);
