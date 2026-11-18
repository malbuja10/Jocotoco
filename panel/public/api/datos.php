<?php

declare(strict_types=1);

/**
 * GET api/datos.php — JSON para el panel.
 *   ?accion=resumen
 *   ?accion=serie&id=<dispositivo>&rango=24h | &desde=YYYY-MM-DD&hasta=YYYY-MM-DD
 */

require dirname(__DIR__, 2) . '/bootstrap.php';

$accion = $_GET['accion'] ?? 'resumen';

if ($accion === 'resumen') {
    json_out([
        'ok' => true,
        'servidor' => date('Y-m-d H:i:s'),
        'umbrales' => ['online_min' => intdiv(cfg()->int('panel_online_seconds'), 60), 'inactivo_h' => round(cfg()->int('panel_idle_seconds') / 3600, 1)],
        'dispositivos' => listar_dispositivos(),
    ]);
}

if ($accion === 'serie') {
    $id = (string) ($_GET['id'] ?? '');
    [$desde, $hasta] = rango($_GET);
    $b = intervalo_para($desde, $hasta);

    $volt = serie_lecturas('voltage_readings', $id, $desde, $hasta, $b);
    $corr = serie_lecturas('current_readings', $id, $desde, $hasta, $b);

    // Potencia: producto de los promedios de voltaje y corriente del mismo intervalo.
    $iPorT = array_column($corr, 'prom', 't');
    $pot = [];
    $wh = 0.0;
    foreach ($volt as $v) {
        if (isset($iPorT[$v['t']])) {
            $p = round($v['prom'] * $iPorT[$v['t']], 3);
            $pot[] = ['t' => $v['t'], 'prom' => $p];
            $wh += $p * $b / 3600;
        }
    }
    $sv = estadisticas('voltage_readings', $id, $desde, $hasta);
    $si = estadisticas('current_readings', $id, $desde, $hasta);
    $sp = null;
    if ($pot) {
        $vals = array_column($pot, 'prom');
        $sp = ['n' => count($vals), 'min' => min($vals), 'max' => max($vals), 'prom' => round(array_sum($vals) / count($vals), 3)];
    }

    $ev = db()->prepare('SELECT event_timestamp, threat_type, confidence_score FROM detection_events
                         WHERE device_id = :d AND event_timestamp BETWEEN :a AND :z ORDER BY event_timestamp');
    $ev->execute([':d' => $id, ':a' => $desde, ':z' => $hasta]);
    $eventos = array_map(static fn ($e) => ['t' => (int) $e['event_timestamp'], 'clase' => etiqueta_clase($e['threat_type']),
        'confianza' => $e['confidence_score'] === null ? null : (float) $e['confidence_score']], $ev->fetchAll());

    // Ultimas lecturas sin agrupar, voltaje y corriente juntos por instante.
    $ult = [];
    foreach (TABLAS_LECTURAS as $nombre => $tabla) {
        $st = db()->prepare("SELECT measured_at, value FROM $tabla WHERE device_id = :d AND measured_at BETWEEN :a AND :z ORDER BY measured_at DESC LIMIT 15");
        $st->execute([':d' => $id, ':a' => $desde, ':z' => $hasta]);
        foreach ($st->fetchAll() as $r) {
            $ult[(int) $r['measured_at']]['t'] = (int) $r['measured_at'];
            $ult[(int) $r['measured_at']][$nombre] = (float) $r['value'];
        }
    }
    krsort($ult);
    $ult = array_slice(array_values($ult), 0, 15);
    foreach ($ult as &$u) {
        $u['fecha'] = fecha($u['t']);
        $u['potencia'] = isset($u['voltaje'], $u['corriente']) ? round($u['voltaje'] * $u['corriente'], 3) : null;
        $u['alerta'] = alerta_electrica($u['voltaje'] ?? null, $u['corriente'] ?? null);
    }
    unset($u);

    json_out([
        'ok' => true,
        'desde' => fecha($desde, 'Y-m-d H:i'), 'hasta' => fecha($hasta, 'Y-m-d H:i'),
        'intervalo_seg' => $b,
        'voltaje' => $volt, 'corriente' => $corr, 'potencia' => $pot,
        'estado' => serie_estado($id, $desde, $hasta, $b),
        'stats' => ['voltaje' => $sv, 'corriente' => $si, 'potencia' => $sp],
        'fuera_de_rango' => [
            'voltaje' => contar_fuera_de_rango('voltage_readings', $id, $desde, $hasta, cfg_float('panel_voltage_min'), cfg_float('panel_voltage_max')),
            'corriente' => contar_fuera_de_rango('current_readings', $id, $desde, $hasta, null, cfg_float('panel_current_max')),
        ],
        'energia_wh' => round($wh, 2),
        'eventos' => $eventos,
        'ultimas' => $ult,
        'limites' => ['vmin' => cfg_float('panel_voltage_min'), 'vmax' => cfg_float('panel_voltage_max'), 'imax' => cfg_float('panel_current_max')],
    ]);
}

json_out(['ok' => false, 'error' => 'Acción desconocida'], 400);
