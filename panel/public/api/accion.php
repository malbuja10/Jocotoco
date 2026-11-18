<?php

declare(strict_types=1);

/**
 * POST api/accion.php — cambios hechos desde el panel (con token CSRF).
 *   accion=revisar  evento=<id> atendida=1|0 [nota]
 */

require dirname(__DIR__, 2) . '/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_out(['ok' => false, 'error' => 'Use POST'], 405);
}
if (!csrf_valido($_POST['csrf'] ?? null)) {
    json_out(['ok' => false, 'error' => 'La sesión expiró: recargue la página.'], 403);
}

if (($_POST['accion'] ?? '') === 'revisar') {
    $evento = (string) ($_POST['evento'] ?? '');
    $existe = db()->prepare('SELECT 1 FROM detection_events WHERE id = :e');
    $existe->execute([':e' => $evento]);
    if (!$existe->fetchColumn()) {
        json_out(['ok' => false, 'error' => 'La detección no existe.'], 404);
    }
    $nota = trim((string) ($_POST['nota'] ?? ''));
    $atendida = revisar_evento($evento, ($_POST['atendida'] ?? '1') === '1', $nota === '' ? null : mb_substr($nota, 0, 1000));
    json_out(['ok' => true, 'atendida' => $atendida, 'por' => usuario_actual(), 'en' => date('Y-m-d H:i')]);
}

json_out(['ok' => false, 'error' => 'Acción desconocida'], 400);
