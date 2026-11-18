#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Carga datos de demostracion para ver el panel funcionando:
 *
 *   php panel/herramientas/datos-demo.php --confirmar
 *
 * Crea cinco equipos "demo-*" con 7 dias de voltaje y corriente (cada
 * minuto), estado del equipo, y detecciones con audio WAV sintetico.
 * Solo toca filas y archivos de los equipos demo-*; para borrarlos:
 *
 *   php panel/herramientas/datos-demo.php --borrar
 *
 * No lo ejecute en el servidor de produccion.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/bootstrap.php';

use Jocotoco\Support\SystemClock;
use Jocotoco\Support\Ulid;

$pdo = db();
$audioDir = cfg()->string('audio_dir');
$borrar = in_array('--borrar', $argv, true);
if (!$borrar && !in_array('--confirmar', $argv, true)) {
    fwrite(STDERR, "Agregue --confirmar para cargar los datos demo, o --borrar para quitarlos.\n");
    exit(1);
}

$pdo->beginTransaction();
$borrarFilas = static function () use ($pdo): void {
    $pdo->exec("DELETE FROM event_reviews WHERE event_ref IN (SELECT id FROM detection_events WHERE device_id LIKE 'demo-%')");
    foreach (['detection_events', 'recordings', 'voltage_readings', 'current_readings', 'device_telemetry'] as $t) {
        $pdo->exec("DELETE FROM $t WHERE device_id LIKE 'demo-%'");
    }
    $pdo->exec("DELETE FROM devices WHERE id LIKE 'demo-%'");
    $pdo->exec("DELETE FROM device_profiles WHERE device_id LIKE 'demo-%'");
};
$borrarFilas();
foreach (glob($audioDir . '/demo-*', GLOB_ONLYDIR) ?: [] as $dir) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($dir);
}
if ($borrar) {
    $pdo->commit();
    echo "Datos demo eliminados.\n";
    exit(0);
}

mt_srand(42);
$reloj = new SystemClock();
$fin = time();
$iso = static fn (int $t): string => gmdate('Y-m-d\TH:i:s\Z', $t);
$equipos = [
    // id, nombre, ubicacion, lat, lon, segundos sin conectar, corriente?
    ['demo-yanacocha-01', 'Estación Yanacocha', 'Reserva Yanacocha, sendero principal', -0.1167, -78.5833, 60, true],
    ['demo-yanacocha-02', 'Yanacocha – Quebrada', 'Reserva Yanacocha, quebrada norte', -0.1102, -78.5791, 120, true],
    ['demo-buenaventura-01', 'Buenaventura – Torre', 'Reserva Buenaventura, torre de observación', -3.6544, -79.7441, 3 * 3600, true],
    ['demo-tapichalaca-01', 'Tapichalaca – Puesto', 'Reserva Tapichalaca, puesto de guardianía', -4.4922, -79.1311, 4 * 86400, false],
    ['demo-narupa-01', 'Narupa – Río', 'Reserva Narupa, orilla del río', -0.6667, -77.7833, 30, true],
];
$clases = ['motosierra' => 0.9, 'disparo' => 0.85, 'vehiculo' => 0.7, 'voces_humanas' => 0.6, 'machete' => 0.65, 'perro' => 0.55];

$wav = static function (string $ruta, string $clase, float $dur): string {
    $sr = 16000; $n = (int) ($sr * $dur); $d = '';
    for ($k = 0; $k < $n; $k++) {
        $t = $k / $sr; $r = mt_rand() / mt_getrandmax() * 2 - 1;
        $s = match ($clase) {
            'motosierra' => 0.5 * sin(2 * M_PI * (110 + 30 * sin(2 * M_PI * 0.7 * $t)) * $t) + 0.25 * $r,
            'disparo' => fmod($t, 1.0) < 0.25 ? exp(-fmod($t, 1.0) * 18) * $r : 0,
            'vehiculo' => 0.45 * sin(2 * M_PI * 60 * $t) * (0.6 + 0.4 * sin(2 * M_PI * 0.5 * $t)) + 0.1 * $r,
            'perro' => fmod($t, 0.8) < 0.18 ? 0.6 * sin(2 * M_PI * (500 - 900 * fmod($t, 0.8)) * $t) : 0,
            default => 0.35 * sin(2 * M_PI * (180 + 60 * sin(2 * M_PI * 3 * $t)) * $t) * (sin(2 * M_PI * 1.5 * $t) > 0 ? 1 : 0.2),
        };
        $s += 0.003 * $r;   // ruido de fondo: cada grabacion es distinta
        $d .= pack('v', (int) max(-32767, min(32767, $s * 26000)) & 0xFFFF);
    }
    $h = 'RIFF' . pack('V', 36 + strlen($d)) . 'WAVEfmt ' . pack('VvvVVvv', 16, 1, 1, $sr, $sr * 2, 2, 16) . 'data' . pack('V', strlen($d));
    file_put_contents($ruta, $h . $d);

    return hash_file('sha256', $ruta);
};

$insV = $pdo->prepare('INSERT INTO voltage_readings (device_id, measured_at, value, received_at) VALUES (?,?,?,?)');
$insI = $pdo->prepare('INSERT INTO current_readings (device_id, measured_at, value, received_at) VALUES (?,?,?,?)');
$insT = $pdo->prepare('INSERT INTO device_telemetry (device_id, measured_at, cpu_temp, cpu_usage_pct, ram_usage_pct, disk_free_gb, disk_usage_pct, uptime_hours, received_at) VALUES (?,?,?,?,?,?,?,?,?)');
$insR = $pdo->prepare('INSERT INTO recordings (id, device_id, cert_fingerprint, format, media_type, size_bytes, sha256, relative_path, original_filename, recorded_at, received_at, metadata) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
$insE = $pdo->prepare('INSERT INTO detection_events (id, device_id, event_id, threat_type, confidence_score, event_timestamp, occurred_at, duration_sec, sample_rate, channels, bit_depth, declared_audio_path, recording_id, payload, received_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
$insRev = $pdo->prepare("INSERT INTO event_reviews (event_ref, status, note, reviewed_by, reviewed_at) VALUES (?, 'atendida', ?, 'demo', ?)");

foreach ($equipos as $idx => [$id, $nombre, $ubic, $lat, $lon, $retraso, $conCorriente]) {
    $ultimo = $fin - $retraso;
    $grabaciones = 0; $bytes = 0;
    for ($t = intdiv($fin - 7 * 86400, 60) * 60; $t <= $ultimo; $t += 60) {
        $hora = (int) date('G', $t) + (int) date('i', $t) / 60;
        $sol = max(0, sin(($hora - 6) / 12 * M_PI));
        $v = 12.3 + 1.6 * $sol - 0.3 * (1 - $sol) + mt_rand(-100, 100) / 1000;
        if ($idx === 1 && $t > $fin - 2 * 86400) {
            $v -= 1.4 + 0.5 * (1 - $sol);          // bateria que se degrada
        }
        $insV->execute([$id, $t, round($v, 3), $iso($t + 30)]);
        if ($conCorriente) {
            $i = 0.45 + 0.35 * $sol + mt_rand(0, 100) / 1000 + (mt_rand(0, 500) === 0 ? 4.8 : 0);
            $insI->execute([$id, $t, round($i, 4), $iso($t + 30)]);
        }
        if (intdiv($t, 60) % 5 === 0) {
            $insT->execute([$id, $t, round(42 + 14 * $sol + mt_rand(-20, 20) / 10, 1), round(18 + 25 * $sol + mt_rand(0, 100) / 10, 1),
                round(38 + mt_rand(0, 80) / 10, 1), round(21.5 - ($fin - $t) / 86400 * -0.05, 2), 64.0, round(($t - ($fin - 9 * 86400)) / 3600, 1), $iso($t + 30)]);
        }
    }
    $dir = "$audioDir/$id/" . date('Y/m', $fin);
    if (!is_dir($dir)) {
        mkdir($dir, 0o750, true);
    }
    $n = mt_rand(8, 20);
    for ($k = 0; $k < $n; $k++) {
        $clase = array_rand($clases);
        $ts = mt_rand($fin - 7 * 86400, $ultimo);
        $dur = mt_rand(20, 50) / 10;
        $recId = Ulid::generate($reloj);
        $archivo = "$clase-$recId.wav";
        $sha = $wav("$dir/$archivo", $clase, $dur);
        $tam = filesize("$dir/$archivo");
        $insR->execute([$recId, $id, 'demo', 'wav', 'audio/wav', $tam, $sha, "$id/" . date('Y/m', $fin) . "/$archivo", "$clase.wav", $iso($ts), $iso($ts + 90), '{}']);
        $conf = min(0.99, max(0.35, $clases[$clase] + mt_rand(-25, 9) / 100));
        $evId = Ulid::generate($reloj);
        $insE->execute([$evId, $id, 'evt-' . $ts . '-' . $k, $clase, $conf, $ts, $iso($ts), $dur, 16000, 1, 16, "/home/pi/grabaciones/$clase.wav", $recId,
            json_encode(['device_id' => $id, 'threat_type' => $clase, 'confidence_score' => $conf, 'timestamp' => $ts]), $iso($ts + 90)]);
        if ($ts < $fin - 2 * 86400) {
            $insRev->execute([$evId, mt_rand(0, 1) ? 'Revisado en campo' : null, $iso($ts + 3600)]);
        }
        $grabaciones++; $bytes += $tam;
    }
    $pdo->prepare('INSERT INTO devices (id, first_seen_at, last_seen_at, last_cert_fingerprint, recordings_count, bytes_stored) VALUES (?,?,?,?,?,?)')
        ->execute([$id, $iso($fin - 9 * 86400), $iso($ultimo), 'demo', $grabaciones, $bytes]);
    $pdo->prepare('INSERT INTO device_profiles (device_id, display_name, location, latitude, longitude, notes, updated_at) VALUES (?,?,?,?,?,?,?)')
        ->execute([$id, $nombre, $ubic, $lat, $lon, 'Equipo de demostración', $iso($fin)]);
    echo "$id: $n detecciones\n";
}
$pdo->commit();
echo "Listo. Audio en $audioDir/demo-*\n";
