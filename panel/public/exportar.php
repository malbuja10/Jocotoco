<?php

declare(strict_types=1);

/** Exporta historicos a CSV: ?id=<disp>&tipo=voltaje|corriente|estado|amenazas&desde=&hasta= */

require dirname(__DIR__) . '/bootstrap.php';

$id = (string) ($_GET['id'] ?? '');
$tipo = (string) ($_GET['tipo'] ?? 'voltaje');
[$desde, $hasta] = rango($_GET, '30d');

$consultas = [
    'voltaje' => ['SELECT measured_at, value, received_at FROM voltage_readings WHERE device_id = :d AND measured_at BETWEEN :a AND :z ORDER BY measured_at',
        ['fecha_local', 'epoch', 'voltaje_v', 'recibido_utc']],
    'corriente' => ['SELECT measured_at, value, received_at FROM current_readings WHERE device_id = :d AND measured_at BETWEEN :a AND :z ORDER BY measured_at',
        ['fecha_local', 'epoch', 'corriente_a', 'recibido_utc']],
    'estado' => ['SELECT measured_at, cpu_temp, cpu_usage_pct, ram_usage_pct, disk_free_gb, disk_usage_pct, uptime_hours, received_at FROM device_telemetry WHERE device_id = :d AND measured_at BETWEEN :a AND :z ORDER BY measured_at',
        ['fecha_local', 'epoch', 'cpu_c', 'cpu_pct', 'ram_pct', 'disco_libre_gb', 'disco_pct', 'uptime_h', 'recibido_utc']],
    'amenazas' => ["SELECT e.event_timestamp, e.event_id, e.threat_type, e.confidence_score, e.duration_sec, e.sample_rate, e.recording_id, e.received_at,
                           CASE WHEN r.event_ref IS NULL THEN 'pendiente' ELSE 'atendida' END, r.reviewed_by, r.note
                    FROM detection_events e LEFT JOIN event_reviews r ON r.event_ref = e.id
                    WHERE e.device_id = :d AND e.event_timestamp BETWEEN :a AND :z ORDER BY e.event_timestamp",
        ['fecha_local', 'epoch', 'event_id', 'clase', 'confianza', 'duracion_s', 'frecuencia_hz', 'grabacion', 'recibido_utc', 'revision', 'revisado_por', 'nota']],
];
if (!isset($consultas[$tipo])) {
    http_response_code(400);
    exit('Tipo inválido');
}

[$sql, $cabecera] = $consultas[$tipo];
$st = db()->prepare($sql);
$st->execute([':d' => $id, ':a' => $desde, ':z' => $hasta]);

$archivo = preg_replace('/[^A-Za-z0-9_-]/', '_', $id) . "_{$tipo}_" . date('Ymd', $desde) . '-' . date('Ymd', $hasta) . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $archivo . '"');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");
fputcsv($out, $cabecera);
while ($r = $st->fetch(PDO::FETCH_NUM)) {
    array_unshift($r, fecha($r[0]));
    fputcsv($out, $r);
}
