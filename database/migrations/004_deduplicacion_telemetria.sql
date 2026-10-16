-- La clave de deduplicacion de la telemetria pasa a incluir el contenido.
--
-- Con la clave anterior (dispositivo + instante) dos lecturas distintas
-- tomadas dentro del mismo segundo se tomaban por un reenvio y la segunda
-- se descartaba en silencio, mientras el monitor borraba las dos de su
-- base local al recibir 200. Incluyendo los valores, un reenvio (que trae
-- exactamente los mismos numeros) se sigue ignorando, pero dos medidas
-- reales del mismo segundo se guardan las dos.

DROP INDEX IF EXISTS ux_voltaje_dispositivo_instante;

CREATE UNIQUE INDEX IF NOT EXISTS ux_voltaje_lectura
    ON voltage_readings (device_id, measured_at, value);

DROP INDEX IF EXISTS ux_telemetria_dispositivo_instante;

-- COALESCE porque en un indice unico de SQLite dos NULL se consideran
-- distintos: sin esto, un reenvio con metricas vacias se duplicaria.
CREATE UNIQUE INDEX IF NOT EXISTS ux_telemetria_registro
    ON device_telemetry (
        device_id,
        measured_at,
        COALESCE(cpu_temp, ''),
        COALESCE(cpu_usage_pct, ''),
        COALESCE(ram_usage_pct, ''),
        COALESCE(disk_free_gb, ''),
        COALESCE(disk_usage_pct, ''),
        COALESCE(uptime_hours, '')
    );
