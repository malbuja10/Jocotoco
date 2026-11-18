<?php

declare(strict_types=1);

/** Utilidades de presentacion del panel. */

function h(mixed $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

/** Usuario autenticado por Apache (AuthType Basic); "operador" si no hay. */
function usuario_actual(): string
{
    return (string) ($_SERVER['REMOTE_USER'] ?? $_SERVER['PHP_AUTH_USER'] ?? 'operador');
}

function csrf_token(): string
{
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(16));
}

function csrf_valido(?string $token): bool
{
    return is_string($token) && isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $token);
}

/** Fecha local legible desde epoch o ISO 8601. */
function fecha(int|string|null $valor, string $formato = 'Y-m-d H:i:s'): string
{
    if ($valor === null || $valor === '') {
        return '—';
    }
    $ts = is_int($valor) || ctype_digit((string) $valor) ? (int) $valor : strtotime((string) $valor);

    return $ts ? date($formato, $ts) : '—';
}

function a_epoch(int|string|null $valor): ?int
{
    if ($valor === null || $valor === '') {
        return null;
    }

    return is_int($valor) || ctype_digit((string) $valor) ? (int) $valor : (strtotime((string) $valor) ?: null);
}

function hace(?int $ts): string
{
    if (!$ts) {
        return '—';
    }
    $s = max(0, time() - $ts);

    return match (true) {
        $s < 60 => "hace {$s} s",
        $s < 3600 => 'hace ' . intdiv($s, 60) . ' min',
        $s < 86400 => 'hace ' . intdiv($s, 3600) . ' h',
        default => 'hace ' . intdiv($s, 86400) . ' d',
    };
}

/** @return array{0:string,1:string} clave css y etiqueta del estado de conexion */
function estado_conexion(?int $ultima): array
{
    if (!$ultima) {
        return ['sin-datos', 'Sin datos'];
    }
    $s = time() - $ultima;
    if ($s <= cfg()->int('panel_online_seconds')) {
        return ['en-linea', 'En línea'];
    }
    if ($s <= cfg()->int('panel_idle_seconds')) {
        return ['inactivo', 'Inactivo'];
    }

    return ['desconectado', 'Desconectado'];
}

function alerta_electrica(?float $v, ?float $i): ?string
{
    if ($v !== null && $v < cfg_float('panel_voltage_min')) {
        return 'Voltaje bajo';
    }
    if ($v !== null && $v > cfg_float('panel_voltage_max')) {
        return 'Sobrevoltaje';
    }
    if ($i !== null && $i > cfg_float('panel_current_max')) {
        return 'Sobrecorriente';
    }

    return null;
}

/** @return array{0:string,1:string,2:string} clase css, icono, etiqueta */
function nivel_confianza(?float $c): array
{
    if ($c === null) {
        return ['baja', '●', 'Sin dato'];
    }
    if ($c >= cfg_float('panel_confidence_high')) {
        return ['alta', '▲', 'Alta'];
    }
    if ($c >= cfg_float('panel_confidence_medium')) {
        return ['media', '◆', 'Media'];
    }

    return ['baja', '●', 'Baja'];
}

function etiqueta_clase(?string $clase): string
{
    $clase = trim((string) $clase);

    return $clase === '' ? 'Sin clasificar' : ucfirst(str_replace(['_', '-'], ' ', $clase));
}

function bytes_legibles(int|float|null $b): string
{
    $b = (float) $b;
    foreach (['B', 'KB', 'MB', 'GB'] as $u) {
        if ($b < 1024 || $u === 'GB') {
            return ($u === 'B' ? (int) $b : number_format($b, 1)) . ' ' . $u;
        }
        $b /= 1024;
    }

    return '';
}

function num(mixed $v, int $dec = 2): string
{
    return $v === null || $v === '' ? '—' : number_format((float) $v, $dec);
}

/** Rango [desde, hasta] en epoch a partir de ?rango= o ?desde=&hasta= (fechas locales). */
function rango(array $q, string $defecto = '24h'): array
{
    $rangos = ['1h' => 3600, '6h' => 21600, '24h' => 86400, '7d' => 604800, '30d' => 2592000, '90d' => 7776000];
    if (!empty($q['desde']) || !empty($q['hasta'])) {
        $desde = !empty($q['desde']) ? strtotime($q['desde'] . ' 00:00:00') : 0;
        $hasta = !empty($q['hasta']) ? strtotime($q['hasta'] . ' 23:59:59') : time();

        return [(int) $desde, (int) $hasta];
    }
    $seg = $rangos[$q['rango'] ?? $defecto] ?? $rangos[$defecto];

    return [time() - $seg, time()];
}

function json_out(mixed $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function layout_inicio(string $titulo, string $activo = ''): void
{
    header('Content-Security-Policy: default-src \'self\'; img-src \'self\' data:; media-src \'self\'; style-src \'self\' \'unsafe-inline\'; script-src \'self\'; frame-ancestors \'none\'');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin'); ?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf" content="<?= h(csrf_token()) ?>">
<title><?= h($titulo) ?> · Monitoreo Jocotoco</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<header class="topbar">
  <a class="marca" href="index.php"><span class="logo">◉</span> <span>Monitoreo Jocotoco</span></a>
  <nav>
    <a href="index.php" class="<?= $activo === 'inicio' ? 'act' : '' ?>">Dispositivos</a>
    <a href="amenazas.php" class="<?= $activo === 'amenazas' ? 'act' : '' ?>">Amenazas</a>
  </nav>
  <span class="usuario muted"><?= h(usuario_actual()) ?></span>
  <button id="tema" class="btn-icono" title="Cambiar tema" aria-label="Cambiar tema"><svg width="16" height="16" viewBox="0 0 16 16" aria-hidden="true"><circle cx="8" cy="8" r="6.5" fill="none" stroke="currentColor" stroke-width="1.5"/><path d="M8 1.5a6.5 6.5 0 0 1 0 13z" fill="currentColor"/></svg></button>
</header>
<main class="contenedor">
<?php
}

/** @param list<string> $scripts */
function layout_fin(array $scripts = []): void
{ ?>
</main>
<footer class="pie">Jocotoco · Panel de monitoreo de dispositivos remotos</footer>
<script src="assets/app.js"></script>
<?php foreach ($scripts as $s) {
    echo '<script src="' . h($s) . '"></script>' . "\n";
} ?>
</body>
</html>
<?php
}
