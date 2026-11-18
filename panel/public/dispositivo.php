<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$id = (string) ($_GET['id'] ?? '');
$d = $id === '' ? null : obtener_dispositivo($id);
if (!$d) {
    http_response_code(404);
    layout_inicio('No encontrado');
    echo '<div class="vacio">Dispositivo no encontrado. <a href="index.php">Volver a la lista</a></div>';
    layout_fin();
    exit;
}

// Guardar la ficha del equipo
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['accion'] ?? '') === 'perfil') {
    if (csrf_valido($_POST['csrf'] ?? null)) {
        guardar_perfil($id, $_POST);
        header('Location: dispositivo.php?' . http_build_query(['id' => $id, 'tab' => 'ficha', 'guardado' => 1]), true, 303);
    } else {
        header('Location: dispositivo.php?' . http_build_query(['id' => $id, 'tab' => 'ficha', 'error' => 'csrf']), true, 303);
    }
    exit;
}

$tab = in_array($_GET['tab'] ?? '', ['mediciones', 'amenazas', 'historicos', 'ficha'], true) ? $_GET['tab'] : 'mediciones';
$perfil = perfil_dispositivo($id) ?? [];
$estado = ultimo_estado($id);
$ultI = ultima_lectura('current_readings', $id);
$url = static fn (array $extra): string => 'dispositivo.php?' . http_build_query(array_merge($_GET, $extra));

layout_inicio($d['nombre']);
?>
<p class="sin-margen"><a href="index.php">← Dispositivos</a></p>
<div class="ficha">
  <div>
    <h1><?= h($d['nombre']) ?> <span class="estado <?= h($d['estado']) ?>"><?= h($d['estado_txt']) ?></span></h1>
    <div class="meta">
      <span>Identificador: <b class="mono"><?= h($id) ?></b></span>
      <?php if (!empty($perfil['location'])): ?><span>Ubicación: <?= h($perfil['location']) ?></span><?php endif; ?>
      <?php if (isset($perfil['latitude'], $perfil['longitude'])): ?><span>Coordenadas: <a target="_blank" rel="noopener" href="https://www.openstreetmap.org/?mlat=<?= h($perfil['latitude']) ?>&amp;mlon=<?= h($perfil['longitude']) ?>#map=15/<?= h($perfil['latitude']) ?>/<?= h($perfil['longitude']) ?>"><?= h($perfil['latitude']) ?>, <?= h($perfil['longitude']) ?></a></span><?php endif; ?>
      <span>Última conexión: <?= h($d['ultima_conexion'] ?? '—') ?> (<?= h($d['hace']) ?>)</span>
    </div>
  </div>
</div>

<div class="kpis">
  <div class="kpi"><div class="et">Voltaje actual</div><div class="val"><?= num($d['voltaje']) ?> <small>V</small></div>
    <div class="det"><?= $d['alerta'] && $d['alerta'] !== 'Sobrecorriente' ? '<span class="alerta-el">⚠ ' . h($d['alerta']) . '</span>' : 'Rango normal ' . h(cfg_float('panel_voltage_min')) . '–' . h(cfg_float('panel_voltage_max')) . ' V' ?></div></div>
  <div class="kpi"><div class="et">Corriente actual</div><div class="val"><?= num($d['corriente'], 3) ?> <small>A</small></div>
    <div class="det"><?= $d['alerta'] === 'Sobrecorriente' ? '<span class="alerta-el">⚠ Sobrecorriente</span>' : ($ultI ? 'Lectura: ' . h(fecha($ultI['measured_at'])) : 'Sin lecturas de corriente') ?></div></div>
  <div class="kpi"><div class="et">Potencia</div><div class="val"><?= num($d['potencia']) ?> <small>W</small></div><div class="det"><?= $d['voltaje_en'] ? 'Voltaje: ' . h($d['voltaje_en']) : 'Sin lecturas' ?></div></div>
  <div class="kpi"><div class="et">Temperatura CPU</div><div class="val"><?= num($estado['cpu_temp'] ?? null, 1) ?> <small>°C</small></div>
    <div class="det">CPU <?= num($estado['cpu_usage_pct'] ?? null, 0) ?> % · RAM <?= num($estado['ram_usage_pct'] ?? null, 0) ?> %</div></div>
  <div class="kpi"><div class="et">Amenazas</div><div class="val"><?= $d['amenazas'] ?></div><div class="det"><?= $d['amenazas_pendientes'] ?> sin atender</div></div>
</div>

<div class="tabs" role="tablist">
  <button data-tab="mediciones" class="<?= $tab === 'mediciones' ? 'act' : '' ?>">Voltaje, corriente y gráficas</button>
  <button data-tab="amenazas" class="<?= $tab === 'amenazas' ? 'act' : '' ?>">Amenazas y audios <span class="cnt"><?= $d['amenazas'] ?></span></button>
  <button data-tab="historicos" class="<?= $tab === 'historicos' ? 'act' : '' ?>">Históricos</button>
  <button data-tab="ficha" class="<?= $tab === 'ficha' ? 'act' : '' ?>">Ficha del equipo</button>
</div>

<!-- ================= MEDICIONES Y GRAFICAS ================= -->
<section class="tab <?= $tab === 'mediciones' ? 'act' : '' ?>" id="tab-mediciones">
  <div class="panel-cab">
    <div class="filtros">
      <div class="seg" id="rangos">
        <button data-r="1h">1 h</button><button data-r="6h">6 h</button><button data-r="24h" class="act">24 h</button><button data-r="7d">7 días</button><button data-r="30d">30 días</button><button data-r="90d">90 días</button>
      </div>
      <span class="muted">o</span>
      <input type="date" id="gDesde" aria-label="Desde"> <input type="date" id="gHasta" aria-label="Hasta"> <button class="btn" id="gAplicar">Aplicar</button>
    </div>
    <div class="filtros"><span class="muted" id="gInfo"></span><label class="muted"><input type="checkbox" id="gAuto" checked> Auto-actualizar</label></div>
  </div>

  <div class="kpis" id="resumenRango"></div>

  <div class="graficas">
    <div class="grafica"><h3>Voltaje (V)</h3><p class="nota">Promedio por intervalo · líneas punteadas: límites <?= h(cfg_float('panel_voltage_min')) ?> V y <?= h(cfg_float('panel_voltage_max')) ?> V · ▲ amenaza detectada</p><div class="lienzo"><canvas id="cVoltaje"></canvas></div><div class="stats" id="sVoltaje"></div></div>
    <div class="grafica"><h3>Corriente (A)</h3><p class="nota">Promedio por intervalo · línea punteada: máximo <?= h(cfg_float('panel_current_max')) ?> A</p><div class="lienzo"><canvas id="cCorriente"></canvas></div><div class="stats" id="sCorriente"></div></div>
    <div class="grafica"><h3>Potencia (W)</h3><p class="nota">Voltaje × corriente de cada intervalo</p><div class="lienzo"><canvas id="cPotencia"></canvas></div><div class="stats" id="sPotencia"></div></div>
    <div class="grafica"><h3>Distribución del voltaje</h3><p class="nota">Intervalos agrupados por su voltaje promedio</p><div class="lienzo"><canvas id="cHisto"></canvas></div></div>
    <div class="grafica"><h3>Temperatura de la CPU (°C)</h3><p class="nota">Estado reportado por el equipo</p><div class="lienzo"><canvas id="cTemp"></canvas></div></div>
    <div class="grafica"><h3>Uso de CPU y RAM (%)</h3><p class="nota">Promedio por intervalo</p><div class="lienzo"><canvas id="cUso"></canvas></div></div>
  </div>

  <div class="panel" id="panelUlt">
    <div class="panel-cab"><h2>Últimas lecturas</h2>
      <div class="filtros"><a class="btn" href="exportar.php?<?= h(http_build_query(['id' => $id, 'tipo' => 'voltaje', 'rango' => '30d'])) ?>">⬇ Voltaje CSV</a><a class="btn" href="exportar.php?<?= h(http_build_query(['id' => $id, 'tipo' => 'corriente', 'rango' => '30d'])) ?>">⬇ Corriente CSV</a></div></div>
    <div class="tabla-cont"><table><thead><tr><th>Fecha</th><th class="num">Voltaje (V)</th><th class="num">Corriente (A)</th><th class="num">Potencia (W)</th><th>Estado</th></tr></thead><tbody id="tUlt"></tbody></table></div>
  </div>
</section>

<!-- ================= AMENAZAS ================= -->
<section class="tab <?= $tab === 'amenazas' ? 'act' : '' ?>" id="tab-amenazas">
<?php
$fClase = (string) ($_GET['clase'] ?? '');
$fRev = (string) ($_GET['revision'] ?? '');
$fConf = (string) ($_GET['confianza'] ?? '');
$porPag = 15;
$pag = max(1, (int) ($_GET['pa'] ?? 1));
$res = listar_eventos(['dispositivo' => $id, 'clase' => $fClase, 'revision' => $fRev, 'confianza' => $fConf], $porPag, ($pag - 1) * $porPag);
$resumen = resumen_amenazas($id);
?>
  <div class="graficas mb">
    <div class="grafica"><h3>Amenazas por día</h3><p class="nota">Últimos 30 días, por fecha de detección</p><div class="lienzo"><canvas id="cAmDia"></canvas></div></div>
    <div class="grafica"><h3>Amenazas por clase</h3><p class="nota">Total histórico · clase asignada por el modelo, no confirmada</p><div class="lienzo"><canvas id="cAmTipo"></canvas></div></div>
  </div>
  <script type="application/json" id="datosAmenazas"><?= json_encode(['dia' => ['l' => array_keys($resumen['por_dia']), 'v' => array_values($resumen['por_dia'])], 'clase' => $resumen['por_clase']], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>

  <div class="panel">
    <div class="panel-cab">
      <h2>Detecciones (<?= $res['total'] ?>)</h2>
      <form class="filtros" method="get">
        <input type="hidden" name="id" value="<?= h($id) ?>"><input type="hidden" name="tab" value="amenazas">
        <select name="clase" aria-label="Clase"><option value="">Todas las clases</option><?php foreach (clases_de_amenaza($id) as $c): ?><option value="<?= h($c) ?>" <?= $c === $fClase ? 'selected' : '' ?>><?= h(etiqueta_clase($c)) ?></option><?php endforeach; ?></select>
        <select name="confianza" aria-label="Confianza"><option value="">Cualquier confianza</option><?php foreach (['alta' => 'Alta', 'media' => 'Media', 'baja' => 'Baja'] as $k => $v): ?><option value="<?= $k ?>" <?= $k === $fConf ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select>
        <select name="revision" aria-label="Revisión"><option value="">Todas</option><option value="pendiente" <?= $fRev === 'pendiente' ? 'selected' : '' ?>>Sin atender</option><option value="atendida" <?= $fRev === 'atendida' ? 'selected' : '' ?>>Atendidas</option></select>
        <button class="btn">Filtrar</button>
        <a class="btn" href="exportar.php?<?= h(http_build_query(['id' => $id, 'tipo' => 'amenazas', 'rango' => '90d'])) ?>">⬇ CSV</a>
      </form>
    </div>
    <?php include __DIR__ . '/../lib/tabla_eventos.php'; ?>
    <?php $tp = (int) ceil($res['total'] / $porPag); if ($tp > 1): ?>
      <div class="paginacion">
        <?php if ($pag > 1): ?><a class="btn" href="<?= h($url(['pa' => $pag - 1, 'tab' => 'amenazas'])) ?>">← Anterior</a><?php endif; ?>
        <span class="muted">Página <?= $pag ?> de <?= $tp ?></span>
        <?php if ($pag < $tp): ?><a class="btn" href="<?= h($url(['pa' => $pag + 1, 'tab' => 'amenazas'])) ?>">Siguiente →</a><?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
</section>

<!-- ================= HISTORICOS ================= -->
<section class="tab <?= $tab === 'historicos' ? 'act' : '' ?>" id="tab-historicos">
<?php
$hTipo = in_array($_GET['ht'] ?? '', ['voltaje', 'corriente', 'estado', 'amenazas'], true) ? $_GET['ht'] : 'voltaje';
$hDesde = (string) ($_GET['hdesde'] ?? date('Y-m-d', strtotime('-6 days')));
$hHasta = (string) ($_GET['hhasta'] ?? date('Y-m-d'));
[$fd, $fh] = rango(['desde' => $hDesde, 'hasta' => $hHasta]);
$porPagH = 50;
$pagH = max(1, (int) ($_GET['ph'] ?? 1));
$offH = ($pagH - 1) * $porPagH;
$diario = [];
$filas = [];
$nH = 0;
if (in_array($hTipo, ['voltaje', 'corriente'], true)) {
    $tabla = TABLAS_LECTURAS[$hTipo];
    $dia = sql_dia('measured_at');
    $st = db()->prepare("SELECT $dia AS dia, COUNT(*) AS n, MIN(value) AS mn, AVG(value) AS prom, MAX(value) AS mx
                         FROM $tabla WHERE device_id = :d AND measured_at BETWEEN :a AND :z GROUP BY $dia ORDER BY dia DESC");
    $st->execute([':d' => $id, ':a' => $fd, ':z' => $fh]);
    $diario = $st->fetchAll();
    $st = db()->prepare("SELECT COUNT(*) FROM $tabla WHERE device_id = :d AND measured_at BETWEEN :a AND :z");
    $st->execute([':d' => $id, ':a' => $fd, ':z' => $fh]);
    $nH = (int) $st->fetchColumn();
    $st = db()->prepare("SELECT measured_at, value, received_at FROM $tabla WHERE device_id = :d AND measured_at BETWEEN :a AND :z ORDER BY measured_at DESC LIMIT $porPagH OFFSET $offH");
    $st->execute([':d' => $id, ':a' => $fd, ':z' => $fh]);
    $filas = $st->fetchAll();
} elseif ($hTipo === 'estado') {
    $st = db()->prepare('SELECT COUNT(*) FROM device_telemetry WHERE device_id = :d AND measured_at BETWEEN :a AND :z');
    $st->execute([':d' => $id, ':a' => $fd, ':z' => $fh]);
    $nH = (int) $st->fetchColumn();
    $st = db()->prepare("SELECT * FROM device_telemetry WHERE device_id = :d AND measured_at BETWEEN :a AND :z ORDER BY measured_at DESC LIMIT $porPagH OFFSET $offH");
    $st->execute([':d' => $id, ':a' => $fd, ':z' => $fh]);
    $filas = $st->fetchAll();
} else {
    $resH = listar_eventos(['dispositivo' => $id, 'desde' => $fd, 'hasta' => $fh], $porPagH, $offH);
    $nH = $resH['total'];
}
$unidad = $hTipo === 'corriente' ? 'A' : 'V';
$dec = $hTipo === 'corriente' ? 3 : 2;
$offsetDia = (new DateTimeZone(date_default_timezone_get()))->getOffset(new DateTimeImmutable());
?>
  <div class="panel">
    <div class="panel-cab">
      <h2>Históricos</h2>
      <form class="filtros" method="get">
        <input type="hidden" name="id" value="<?= h($id) ?>"><input type="hidden" name="tab" value="historicos">
        <select name="ht" aria-label="Tipo de dato">
          <?php foreach (['voltaje' => 'Voltaje', 'corriente' => 'Corriente', 'estado' => 'Estado del equipo', 'amenazas' => 'Amenazas'] as $k => $v): ?>
          <option value="<?= $k ?>" <?= $hTipo === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?>
        </select>
        <label class="muted">Desde <input type="date" name="hdesde" value="<?= h($hDesde) ?>"></label>
        <label class="muted">Hasta <input type="date" name="hhasta" value="<?= h($hHasta) ?>"></label>
        <button class="btn prim">Consultar</button>
        <a class="btn" href="exportar.php?<?= h(http_build_query(['id' => $id, 'tipo' => $hTipo, 'desde' => $hDesde, 'hasta' => $hHasta])) ?>">⬇ Exportar CSV</a>
      </form>
    </div>

    <?php if ($diario): ?>
    <h3 class="h3">Resumen diario</h3>
    <div class="tabla-cont mb"><table>
      <thead><tr><th>Día</th><th class="num">Lecturas</th><th class="num">Mínimo (<?= $unidad ?>)</th><th class="num">Promedio (<?= $unidad ?>)</th><th class="num">Máximo (<?= $unidad ?>)</th></tr></thead>
      <tbody><?php foreach ($diario as $x): ?>
        <tr><td><?= h(gmdate('Y-m-d', (int) $x['dia'] * 86400)) ?></td><td class="num"><?= (int) $x['n'] ?></td><td class="num"><?= num($x['mn'], $dec) ?></td><td class="num"><?= num($x['prom'], $dec) ?></td><td class="num"><?= num($x['mx'], $dec) ?></td></tr>
      <?php endforeach; ?></tbody></table></div>
    <h3 class="h3">Detalle de registros</h3>
    <?php endif; ?>

    <?php if ($hTipo === 'amenazas'): $res = $resH; include __DIR__ . '/../lib/tabla_eventos.php';
    elseif (!$filas): ?><div class="vacio">Sin registros en el período seleccionado.</div>
    <?php else: ?>
    <div class="tabla-cont"><table>
      <?php if ($hTipo === 'estado'): ?>
        <thead><tr><th>Medido</th><th class="num">CPU (°C)</th><th class="num">CPU (%)</th><th class="num">RAM (%)</th><th class="num">Disco libre (GB)</th><th class="num">Disco (%)</th><th class="num">Encendido (h)</th><th>Recibido</th></tr></thead><tbody>
        <?php foreach ($filas as $r): ?>
          <tr><td class="nowrap"><?= h(fecha($r['measured_at'])) ?></td><td class="num"><?= num($r['cpu_temp'], 1) ?></td><td class="num"><?= num($r['cpu_usage_pct'], 1) ?></td><td class="num"><?= num($r['ram_usage_pct'], 1) ?></td><td class="num"><?= num($r['disk_free_gb'], 2) ?></td><td class="num"><?= num($r['disk_usage_pct'], 1) ?></td><td class="num"><?= num($r['uptime_hours'], 1) ?></td><td class="muted"><?= h(fecha($r['received_at'])) ?></td></tr>
        <?php endforeach; ?>
      <?php else: ?>
        <thead><tr><th>Medido</th><th class="num"><?= $hTipo === 'corriente' ? 'Corriente (A)' : 'Voltaje (V)' ?></th><th>Observación</th><th>Recibido</th></tr></thead><tbody>
        <?php foreach ($filas as $r): $al = $hTipo === 'corriente' ? alerta_electrica(null, (float) $r['value']) : alerta_electrica((float) $r['value'], null); ?>
          <tr><td class="nowrap"><?= h(fecha($r['measured_at'])) ?></td><td class="num"><?= num($r['value'], $dec) ?></td><td><?= $al ? '<span class="alerta-el">⚠ ' . h($al) . '</span>' : '<span class="muted">Normal</span>' ?></td><td class="muted"><?= h(fecha($r['received_at'])) ?></td></tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody></table></div>
    <?php endif; ?>
    <?php $tpH = (int) ceil($nH / $porPagH); if ($tpH > 1): ?>
      <div class="paginacion">
        <?php if ($pagH > 1): ?><a class="btn" href="<?= h($url(['ph' => $pagH - 1, 'tab' => 'historicos'])) ?>">← Anterior</a><?php endif; ?>
        <span class="muted"><?= $nH ?> registros · Página <?= $pagH ?> de <?= $tpH ?></span>
        <?php if ($pagH < $tpH): ?><a class="btn" href="<?= h($url(['ph' => $pagH + 1, 'tab' => 'historicos'])) ?>">Siguiente →</a><?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
</section>

<!-- ================= FICHA ================= -->
<section class="tab <?= $tab === 'ficha' ? 'act' : '' ?>" id="tab-ficha">
  <?php if (isset($_GET['guardado'])): ?><div class="aviso ok-msg">✓ Ficha guardada.</div><?php endif; ?>
  <?php if (isset($_GET['error'])): ?><div class="aviso">La sesión expiró; vuelva a intentarlo.</div><?php endif; ?>
  <div class="graficas">
    <div class="panel">
      <h2>Datos del equipo</h2>
      <form method="post" class="formulario">
        <input type="hidden" name="accion" value="perfil"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <label>Nombre visible<input name="nombre" maxlength="120" value="<?= h($perfil['display_name'] ?? '') ?>" placeholder="<?= h($id) ?>"></label>
        <label>Ubicación<input name="ubicacion" maxlength="200" value="<?= h($perfil['location'] ?? '') ?>" placeholder="Reserva, sector, estación"></label>
        <label>Latitud<input name="latitud" inputmode="decimal" value="<?= h($perfil['latitude'] ?? '') ?>" placeholder="-0.1807"></label>
        <label>Longitud<input name="longitud" inputmode="decimal" value="<?= h($perfil['longitude'] ?? '') ?>" placeholder="-78.4678"></label>
        <label class="ancho">Notas<textarea name="notas" maxlength="2000"><?= h($perfil['notes'] ?? '') ?></textarea></label>
        <div><button class="btn prim">Guardar ficha</button></div>
      </form>
    </div>
    <div class="panel">
      <h2>Información técnica</h2>
      <div class="tabla-cont"><table><tbody>
        <tr><th>Identificador (certificado)</th><td class="mono"><?= h($id) ?></td></tr>
        <tr><th>Primera conexión</th><td><?= h($d['primera_conexion'] ?? '—') ?></td></tr>
        <tr><th>Última conexión</th><td><?= h($d['ultima_conexion'] ?? '—') ?></td></tr>
        <tr><th>Grabaciones recibidas</th><td><?= (int) $d['grabaciones'] ?></td></tr>
        <tr><th>Último estado</th><td><?= $estado ? h(fecha($estado['measured_at'])) : '—' ?></td></tr>
        <tr><th>Disco libre</th><td><?= $estado ? num($estado['disk_free_gb'], 1) . ' GB (' . num($estado['disk_usage_pct'], 0) . ' % usado)' : '—' ?></td></tr>
        <tr><th>Tiempo encendido</th><td><?= $estado ? num($estado['uptime_hours'], 1) . ' h' : '—' ?></td></tr>
      </tbody></table></div>
    </div>
  </div>
</section>

<span id="dispId" data-id="<?= h($id) ?>" hidden></span>
<?php layout_fin([
    'assets/vendor/chart.umd.min.js',
    'assets/vendor/chartjs-adapter-date-fns.bundle.min.js',
    'assets/dispositivo.js',
]);
