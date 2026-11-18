-- Datos propios del panel web de monitoreo (panel/).
-- El API no los lee ni los escribe: son la ficha del equipo que mantienen
-- los operadores y la revision de cada deteccion.

CREATE TABLE IF NOT EXISTS device_profiles (
    device_id    TEXT PRIMARY KEY,
    display_name TEXT,
    location     TEXT,
    latitude     DOUBLE PRECISION,
    longitude    DOUBLE PRECISION,
    notes        TEXT,
    updated_at   TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS event_reviews (
    event_ref   TEXT PRIMARY KEY REFERENCES detection_events (id) ON DELETE CASCADE,
    status      TEXT NOT NULL DEFAULT 'atendida',
    note        TEXT,
    reviewed_by TEXT,
    reviewed_at TEXT NOT NULL
);
