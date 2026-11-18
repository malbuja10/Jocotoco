<?php

declare(strict_types=1);

/**
 * GET audio.php?id=<grabacion>[&descargar=1]
 *
 * Sirve el audio guardado por el API. Los archivos viven fuera del
 * DocumentRoot (/var/lib/jocotoco/audio); aqui se comprueba que la ruta
 * registrada quede dentro de ese directorio y se atienden peticiones Range,
 * que el reproductor del navegador usa para adelantar y retroceder.
 */

require dirname(__DIR__) . '/bootstrap.php';

$id = (string) ($_GET['id'] ?? '');
$st = db()->prepare('SELECT relative_path, media_type, format, original_filename FROM recordings WHERE id = :id');
$st->execute([':id' => $id]);
$g = $st->fetch();

$raiz = realpath(cfg()->string('audio_dir'));
$ruta = $g ? realpath($raiz . '/' . ltrim((string) $g['relative_path'], '/')) : false;
if (!$g || $raiz === false || $ruta === false || !str_starts_with($ruta, $raiz . DIRECTORY_SEPARATOR) || !is_file($ruta)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Audio no encontrado');
}

$tam = filesize($ruta);
$ini = 0;
$fin = $tam - 1;
if (isset($_SERVER['HTTP_RANGE']) && preg_match('/^bytes=(\d*)-(\d*)$/', trim($_SERVER['HTTP_RANGE']), $m)) {
    if ($m[1] === '' && $m[2] !== '') {
        $ini = max(0, $tam - (int) $m[2]);
    } else {
        $ini = (int) $m[1];
        $fin = $m[2] !== '' ? min((int) $m[2], $tam - 1) : $fin;
    }
    if ($ini > $fin || $ini >= $tam) {
        http_response_code(416);
        header("Content-Range: bytes */$tam");
        exit;
    }
    http_response_code(206);
    header("Content-Range: bytes $ini-$fin/$tam");
}

$nombre = preg_replace('/[^A-Za-z0-9._-]/', '_', (string) ($g['original_filename'] ?: basename($ruta)));
header('Content-Type: ' . ($g['media_type'] ?: 'application/octet-stream'));
header('Accept-Ranges: bytes');
header('Content-Length: ' . ($fin - $ini + 1));
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');
header(sprintf('Content-Disposition: %s; filename="%s"', isset($_GET['descargar']) ? 'attachment' : 'inline', $nombre));

$f = fopen($ruta, 'rb');
fseek($f, $ini);
$pendiente = $fin - $ini + 1;
while ($pendiente > 0 && !feof($f)) {
    $bloque = fread($f, (int) min(262144, $pendiente));
    echo $bloque;
    $pendiente -= strlen($bloque);
    flush();
}
fclose($f);
