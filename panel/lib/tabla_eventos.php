<?php
/** Tabla de detecciones. Espera $res = ['filas' => ..., 'total' => ...]. */
if (!$res['filas']): ?>
  <div class="vacio">No hay detecciones con estos filtros.</div>
<?php else: ?>
  <div class="tabla-cont"><table>
    <thead><tr><th>Detectado</th><?php if (!empty($mostrarDispositivo)): ?><th>Dispositivo</th><?php endif; ?><th>Clase</th><th>Confianza</th><th>Audio</th><th>Revisión</th></tr></thead>
    <tbody>
    <?php foreach ($res['filas'] as $e):
        [$nivelCss, $nivelIc, $nivelTxt] = nivel_confianza($e['confidence_score'] === null ? null : (float) $e['confidence_score']);
        $atendida = $e['revision'] !== null; ?>
      <tr class="<?= $atendida ? 'atendida' : '' ?>">
        <td class="nowrap"><?= h(fecha($e['event_timestamp'] ?? $e['received_at'])) ?><div class="muted pequeno">recibido <?= h(fecha($e['received_at'], 'Y-m-d H:i')) ?></div></td>
        <?php if (!empty($mostrarDispositivo)): ?><td><a href="dispositivo.php?<?= h(http_build_query(['id' => $e['device_id'], 'tab' => 'amenazas'])) ?>"><?= h($e['nombre'] ?? $e['device_id']) ?></a><div class="muted pequeno mono"><?= h($e['device_id']) ?></div></td><?php endif; ?>
        <td><b><?= h(etiqueta_clase($e['threat_type'])) ?></b><div class="muted pequeno mono" title="Identificador del evento en el equipo"><?= h($e['event_id']) ?></div></td>
        <td><span class="chip nivel-<?= $nivelCss ?>"><span class="ic"><?= $nivelIc ?></span><?= $e['confidence_score'] !== null ? number_format((float) $e['confidence_score'] * 100, 1) . ' %' : $nivelTxt ?></span></td>
        <td><?php if ($e['recording_id']): $src = 'audio.php?' . http_build_query(['id' => $e['recording_id']]); ?>
            <audio controls preload="none" src="<?= h($src) ?>"></audio>
            <div class="pequeno"><a href="<?= h($src) ?>&amp;descargar=1">⬇ Descargar</a>
              <span class="muted"><?= $e['duration_sec'] ? ' · ' . num($e['duration_sec'], 1) . ' s' : '' ?><?= $e['sample_rate'] ? ' · ' . number_format((int) $e['sample_rate'] / 1000, 1) . ' kHz' : '' ?><?= $e['tamano'] ? ' · ' . h(bytes_legibles($e['tamano'])) : '' ?></span></div>
          <?php else: ?><span class="muted">Sin audio</span><?php endif; ?></td>
        <td><button class="btn btn-atender" data-id="<?= h($e['id']) ?>" data-atendida="<?= $atendida ? '1' : '0' ?>"><?= $atendida ? '✓ Atendida' : 'Marcar atendida' ?></button>
          <div class="muted pequeno revisor"><?= $atendida ? h(($e['reviewed_by'] ?? '') . ' · ' . fecha($e['reviewed_at'], 'Y-m-d H:i')) : '' ?></div></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div>
<?php endif;
