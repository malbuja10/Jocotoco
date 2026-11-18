<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$fDisp = (string) ($_GET['disp'] ?? '');
$fClase = (string) ($_GET['clase'] ?? '');
$fConf = (string) ($_GET['confianza'] ?? '');
$fRev = (string) ($_GET['revision'] ?? 'pendiente');
$porPag = 25;
$pag = max(1, (int) ($_GET['p'] ?? 1));
$res = listar_eventos(['dispositivo' => $fDisp, 'clase' => $fClase, 'confianza' => $fConf, 'revision' => $fRev], $porPag, ($pag - 1) * $porPag);
$dispositivos = listar_dispositivos();
$nombres = array_column($dispositivos, 'nombre', 'id');
foreach ($res['filas'] as &$fila) {
    $fila['nombre'] = $nombres[$fila['device_id']] ?? $fila['device_id'];
}
unset($fila);
$mostrarDispositivo = true;

layout_inicio('Amenazas', 'amenazas');
?>
<h1>Amenazas detectadas</h1>
<p class="sub">Detecciones de todos los dispositivos con su grabación. La clase la asigna el modelo del equipo: escuche el audio para confirmarla.</p>
<div class="panel">
  <div class="panel-cab">
    <h2><?= $res['total'] ?> detecciones</h2>
    <form class="filtros" method="get">
      <select name="disp" aria-label="Dispositivo"><option value="">Todos los dispositivos</option><?php foreach ($dispositivos as $x): ?><option value="<?= h($x['id']) ?>" <?= $x['id'] === $fDisp ? 'selected' : '' ?>><?= h($x['nombre']) ?></option><?php endforeach; ?></select>
      <select name="clase" aria-label="Clase"><option value="">Todas las clases</option><?php foreach (clases_de_amenaza() as $c): ?><option value="<?= h($c) ?>" <?= $c === $fClase ? 'selected' : '' ?>><?= h(etiqueta_clase($c)) ?></option><?php endforeach; ?></select>
      <select name="confianza" aria-label="Confianza"><option value="">Cualquier confianza</option><?php foreach (['alta' => 'Alta', 'media' => 'Media', 'baja' => 'Baja'] as $k => $v): ?><option value="<?= $k ?>" <?= $k === $fConf ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select>
      <select name="revision" aria-label="Revisión"><option value="" <?= $fRev === '' ? 'selected' : '' ?>>Todas</option><option value="pendiente" <?= $fRev === 'pendiente' ? 'selected' : '' ?>>Sin atender</option><option value="atendida" <?= $fRev === 'atendida' ? 'selected' : '' ?>>Atendidas</option></select>
      <button class="btn">Filtrar</button>
    </form>
  </div>
  <?php include dirname(__DIR__) . '/lib/tabla_eventos.php'; ?>
  <?php $tp = (int) ceil($res['total'] / $porPag); if ($tp > 1): $q = $_GET; ?>
    <div class="paginacion">
      <?php if ($pag > 1): ?><a class="btn" href="amenazas.php?<?= h(http_build_query(array_merge($q, ['p' => $pag - 1]))) ?>">← Anterior</a><?php endif; ?>
      <span class="muted">Página <?= $pag ?> de <?= $tp ?></span>
      <?php if ($pag < $tp): ?><a class="btn" href="amenazas.php?<?= h(http_build_query(array_merge($q, ['p' => $pag + 1]))) ?>">Siguiente →</a><?php endif; ?>
    </div>
  <?php endif; ?>
</div>
<?php layout_fin();
