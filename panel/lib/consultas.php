<?php

declare(strict_types=1);

/**
 * Consultas del panel. SQL comun a PostgreSQL y SQLite: el panel funciona
 * con el mismo motor que tenga configurado el API.
 *
 * Las marcas measured_at / event_timestamp son epoch en segundos (las pone
 * el dispositivo); received_at / last_seen_at son ISO 8601 UTC (las pone el
 * servidor).
 */

const TABLAS_LECTURAS = ['voltaje' => 'voltage_readings', 'corriente' => 'current_readings'];

/** Expresion SQL del dia local de una columna epoch (desfase fijo de la zona del panel). */
function sql_dia(string $columna): string
{
    $desfase = (new DateTimeZone(date_default_timezone_get()))->getOffset(new DateTimeImmutable());

    return sprintf('((%s + (%d)) / 86400)', $columna, $desfase);
}

/**
 * Inventario: dispositivos que han hablado con el API, mas los que solo
 * tienen ficha en el panel. Con su ultima lectura y sus pendientes.
 *
 * @return list<array<string,mixed>>
 */
function listar_dispositivos(): array
{
    $pdo = db();
    $porId = [];

    foreach ($pdo->query('SELECT * FROM devices') as $d) {
        $porId[$d['id']] = ['id' => $d['id'], 'ultima_conexion' => a_epoch($d['last_seen_at']),
            'primera_conexion' => a_epoch($d['first_seen_at']), 'grabaciones' => (int) $d['recordings_count'],
            'bytes' => (int) $d['bytes_stored']];
    }
    foreach ($pdo->query('SELECT * FROM device_profiles') as $p) {
        $porId[$p['device_id']] ??= ['id' => $p['device_id'], 'ultima_conexion' => null, 'primera_conexion' => null,
            'grabaciones' => 0, 'bytes' => 0];
        $porId[$p['device_id']]['perfil'] = $p;
    }

    $pendientes = [];
    foreach ($pdo->query('SELECT e.device_id, COUNT(*) AS n FROM detection_events e
                          LEFT JOIN event_reviews r ON r.event_ref = e.id
                          WHERE r.event_ref IS NULL GROUP BY e.device_id') as $f) {
        $pendientes[$f['device_id']] = (int) $f['n'];
    }
    $totales = [];
    foreach ($pdo->query('SELECT device_id, COUNT(*) AS n, MAX(event_timestamp) AS ultimo FROM detection_events GROUP BY device_id') as $f) {
        $totales[$f['device_id']] = $f;
    }

    $lista = [];
    foreach ($porId as $id => $d) {
        $v = ultima_lectura('voltage_readings', $id);
        $i = ultima_lectura('current_readings', $id);
        $t = ultimo_estado($id);
        $perfil = $d['perfil'] ?? [];
        // La ultima conexion al API manda; si no la hay, la ultima medida.
        $ultima = $d['ultima_conexion'] ?? max($v['measured_at'] ?? 0, $i['measured_at'] ?? 0, $t['measured_at'] ?? 0) ?: null;
        [$estado, $estadoTxt] = estado_conexion($ultima);
        $vol = $v ? (float) $v['value'] : null;
        $cor = $i ? (float) $i['value'] : null;
        $lista[] = [
            'id' => $id,
            'nombre' => ($perfil['display_name'] ?? '') ?: $id,
            'ubicacion' => $perfil['location'] ?? null,
            'estado' => $estado,
            'estado_txt' => $estadoTxt,
            'ultima_conexion' => $ultima ? date('Y-m-d H:i:s', $ultima) : null,
            'primera_conexion' => $d['primera_conexion'] ? date('Y-m-d H:i:s', $d['primera_conexion']) : null,
            'hace' => hace($ultima),
            'voltaje' => $vol,
            'voltaje_en' => isset($v['measured_at']) ? fecha($v['measured_at']) : null,
            'corriente' => $cor,
            'potencia' => $vol !== null && $cor !== null ? round($vol * $cor, 3) : null,
            'cpu_temp' => isset($t['cpu_temp']) ? (float) $t['cpu_temp'] : null,
            'alerta' => alerta_electrica($vol, $cor),
            'amenazas' => (int) ($totales[$id]['n'] ?? 0),
            'amenazas_pendientes' => $pendientes[$id] ?? 0,
            'ultima_amenaza' => isset($totales[$id]['ultimo']) ? fecha($totales[$id]['ultimo']) : null,
            'grabaciones' => $d['grabaciones'],
        ];
    }
    usort($lista, static fn ($a, $b) => strnatcasecmp($a['nombre'], $b['nombre']));

    return $lista;
}

/** @return array<string,mixed>|null */
function ultima_lectura(string $tabla, string $dispositivo): ?array
{
    $st = db()->prepare("SELECT measured_at, value FROM $tabla WHERE device_id = :d ORDER BY measured_at DESC LIMIT 1");
    $st->execute([':d' => $dispositivo]);

    return $st->fetch() ?: null;
}

/** @return array<string,mixed>|null */
function ultimo_estado(string $dispositivo): ?array
{
    $st = db()->prepare('SELECT * FROM device_telemetry WHERE device_id = :d ORDER BY measured_at DESC LIMIT 1');
    $st->execute([':d' => $dispositivo]);

    return $st->fetch() ?: null;
}

/** @return array<string,mixed>|null */
function obtener_dispositivo(string $id): ?array
{
    foreach (listar_dispositivos() as $d) {
        if ($d['id'] === $id) {
            return $d;
        }
    }

    return null;
}

/** @return array<string,mixed>|null */
function perfil_dispositivo(string $id): ?array
{
    $st = db()->prepare('SELECT * FROM device_profiles WHERE device_id = :d');
    $st->execute([':d' => $id]);

    return $st->fetch() ?: null;
}

/** Tamano de intervalo para que una serie no pase de ~600 puntos. */
function intervalo_para(int $desde, int $hasta): int
{
    $span = max(60, $hasta - $desde);
    foreach ([60, 120, 300, 600, 900, 1800, 3600, 7200, 10800, 21600, 43200, 86400] as $b) {
        if ($span / $b <= 600) {
            return $b;
        }
    }

    return 86400;
}

/**
 * Serie agrupada en intervalos de $b segundos: promedio, minimo y maximo.
 *
 * @return list<array{t:int,prom:float,min:float,max:float,n:int}>
 */
function serie_lecturas(string $tabla, string $disp, int $desde, int $hasta, int $b): array
{
    $st = db()->prepare(
        "SELECT (measured_at / $b) * $b AS t, AVG(value) AS prom, MIN(value) AS mn, MAX(value) AS mx, COUNT(*) AS n
         FROM $tabla WHERE device_id = :d AND measured_at BETWEEN :a AND :z
         GROUP BY (measured_at / $b) ORDER BY t",
    );
    $st->execute([':d' => $disp, ':a' => $desde, ':z' => $hasta]);

    return array_map(static fn ($r) => ['t' => (int) $r['t'], 'prom' => round((float) $r['prom'], 4),
        'min' => (float) $r['mn'], 'max' => (float) $r['mx'], 'n' => (int) $r['n']], $st->fetchAll());
}

/** @return array{n:int,min:?float,max:?float,prom:?float,desv:?float,primera:?int,ultima:?int} */
function estadisticas(string $tabla, string $disp, int $desde, int $hasta): array
{
    $st = db()->prepare(
        "SELECT COUNT(*) AS n, MIN(value) AS mn, MAX(value) AS mx, AVG(value) AS prom, AVG(value * value) AS cuad,
                MIN(measured_at) AS primera, MAX(measured_at) AS ultima
         FROM $tabla WHERE device_id = :d AND measured_at BETWEEN :a AND :z",
    );
    $st->execute([':d' => $disp, ':a' => $desde, ':z' => $hasta]);
    $r = $st->fetch();
    $n = (int) $r['n'];
    if ($n === 0) {
        return ['n' => 0, 'min' => null, 'max' => null, 'prom' => null, 'desv' => null, 'primera' => null, 'ultima' => null];
    }
    $prom = (float) $r['prom'];

    return ['n' => $n, 'min' => (float) $r['mn'], 'max' => (float) $r['mx'], 'prom' => round($prom, 4),
        'desv' => round(sqrt(max(0.0, (float) $r['cuad'] - $prom * $prom)), 4),
        'primera' => (int) $r['primera'], 'ultima' => (int) $r['ultima']];
}

/** Lecturas fuera del rango normal en el periodo. */
function contar_fuera_de_rango(string $tabla, string $disp, int $desde, int $hasta, ?float $min, ?float $max): int
{
    $cond = [];
    $par = [':d' => $disp, ':a' => $desde, ':z' => $hasta];
    if ($min !== null) {
        $cond[] = 'value < :mn';
        $par[':mn'] = $min;
    }
    if ($max !== null) {
        $cond[] = 'value > :mx';
        $par[':mx'] = $max;
    }
    if (!$cond) {
        return 0;
    }
    $st = db()->prepare("SELECT COUNT(*) FROM $tabla WHERE device_id = :d AND measured_at BETWEEN :a AND :z AND (" . implode(' OR ', $cond) . ')');
    $st->execute($par);

    return (int) $st->fetchColumn();
}

/** @return list<array<string,mixed>> */
function serie_estado(string $disp, int $desde, int $hasta, int $b): array
{
    $st = db()->prepare(
        "SELECT (measured_at / $b) * $b AS t, AVG(cpu_temp) AS cpu_temp, AVG(cpu_usage_pct) AS cpu,
                AVG(ram_usage_pct) AS ram, AVG(disk_usage_pct) AS disco
         FROM device_telemetry WHERE device_id = :d AND measured_at BETWEEN :a AND :z
         GROUP BY (measured_at / $b) ORDER BY t",
    );
    $st->execute([':d' => $disp, ':a' => $desde, ':z' => $hasta]);
    $f = static fn ($x) => $x === null ? null : round((float) $x, 2);

    return array_map(static fn ($r) => ['t' => (int) $r['t'], 'cpu_temp' => $f($r['cpu_temp']), 'cpu' => $f($r['cpu']),
        'ram' => $f($r['ram']), 'disco' => $f($r['disco'])], $st->fetchAll());
}

/**
 * Detecciones con su grabacion y su revision.
 *
 * @param array{dispositivo?:string,clase?:string,revision?:string,confianza?:string,desde?:int,hasta?:int} $f
 * @return array{filas:list<array<string,mixed>>,total:int}
 */
function listar_eventos(array $f, int $limite = 25, int $offset = 0): array
{
    $donde = ['1=1'];
    $par = [];
    if (!empty($f['dispositivo'])) {
        $donde[] = 'e.device_id = :disp';
        $par[':disp'] = $f['dispositivo'];
    }
    if (($f['clase'] ?? '') !== '') {
        $donde[] = "COALESCE(e.threat_type, '') = :clase";
        $par[':clase'] = $f['clase'];
    }
    if (($f['revision'] ?? '') === 'pendiente') {
        $donde[] = 'r.event_ref IS NULL';
    } elseif (($f['revision'] ?? '') === 'atendida') {
        $donde[] = 'r.event_ref IS NOT NULL';
    }
    if (($f['confianza'] ?? '') === 'alta') {
        $donde[] = 'e.confidence_score >= :ch';
        $par[':ch'] = cfg_float('panel_confidence_high');
    } elseif (($f['confianza'] ?? '') === 'media') {
        $donde[] = 'e.confidence_score >= :cm AND e.confidence_score < :ch';
        $par[':cm'] = cfg_float('panel_confidence_medium');
        $par[':ch'] = cfg_float('panel_confidence_high');
    } elseif (($f['confianza'] ?? '') === 'baja') {
        $donde[] = '(e.confidence_score IS NULL OR e.confidence_score < :cm)';
        $par[':cm'] = cfg_float('panel_confidence_medium');
    }
    if (!empty($f['desde'])) {
        $donde[] = 'e.event_timestamp >= :desde';
        $par[':desde'] = $f['desde'];
    }
    if (!empty($f['hasta'])) {
        $donde[] = 'e.event_timestamp <= :hasta';
        $par[':hasta'] = $f['hasta'];
    }
    $where = implode(' AND ', $donde);
    $base = "FROM detection_events e
             LEFT JOIN event_reviews r ON r.event_ref = e.id
             LEFT JOIN recordings g ON g.id = e.recording_id
             WHERE $where";

    $st = db()->prepare("SELECT COUNT(*) $base");
    $st->execute($par);
    $total = (int) $st->fetchColumn();

    $st = db()->prepare(
        "SELECT e.id, e.device_id, e.event_id, e.threat_type, e.confidence_score, e.event_timestamp,
                e.duration_sec, e.sample_rate, e.channels, e.bit_depth, e.recording_id, e.received_at,
                r.status AS revision, r.reviewed_by, r.reviewed_at, r.note AS nota,
                g.format AS formato, g.size_bytes AS tamano, g.original_filename AS archivo
         $base
         ORDER BY COALESCE(e.event_timestamp, 0) DESC, e.received_at DESC
         LIMIT " . max(1, $limite) . ' OFFSET ' . max(0, $offset),
    );
    $st->execute($par);

    return ['filas' => $st->fetchAll(), 'total' => $total];
}

/** @return list<string> */
function clases_de_amenaza(?string $disp = null): array
{
    $sql = "SELECT DISTINCT COALESCE(threat_type, '') AS c FROM detection_events" . ($disp ? ' WHERE device_id = :d' : '') . ' ORDER BY c';
    $st = db()->prepare($sql);
    $st->execute($disp ? [':d' => $disp] : []);

    return $st->fetchAll(PDO::FETCH_COLUMN);
}

/** @return array{por_dia:array<string,int>,por_clase:list<array{clase:string,n:int}>} */
function resumen_amenazas(string $disp, int $dias = 30): array
{
    $desde = strtotime('today -' . ($dias - 1) . ' days');
    $porDia = [];
    for ($k = $dias - 1; $k >= 0; $k--) {
        $porDia[date('Y-m-d', strtotime("today -$k days"))] = 0;
    }
    $st = db()->prepare('SELECT event_timestamp FROM detection_events WHERE device_id = :d AND event_timestamp >= :a');
    $st->execute([':d' => $disp, ':a' => $desde]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $ts) {
        $k = date('Y-m-d', (int) $ts);
        if (isset($porDia[$k])) {
            $porDia[$k]++;
        }
    }
    $st = db()->prepare("SELECT COALESCE(threat_type, '') AS clase, COUNT(*) AS n FROM detection_events
                         WHERE device_id = :d GROUP BY COALESCE(threat_type, '') ORDER BY n DESC");
    $st->execute([':d' => $disp]);
    $porClase = array_map(static fn ($r) => ['clase' => etiqueta_clase($r['clase']), 'n' => (int) $r['n']], $st->fetchAll());

    return ['por_dia' => $porDia, 'por_clase' => $porClase];
}

/** Marca o desmarca una deteccion como atendida. Devuelve el estado final. */
function revisar_evento(string $eventoId, bool $atendida, ?string $nota): bool
{
    $pdo = db();
    if (!$atendida) {
        $pdo->prepare('DELETE FROM event_reviews WHERE event_ref = :e')->execute([':e' => $eventoId]);

        return false;
    }
    $pdo->prepare(
        "INSERT INTO event_reviews (event_ref, status, note, reviewed_by, reviewed_at)
         VALUES (:e, 'atendida', :nota, :por, :en)
         ON CONFLICT (event_ref) DO UPDATE SET note = excluded.note, reviewed_by = excluded.reviewed_by, reviewed_at = excluded.reviewed_at",
    )->execute([':e' => $eventoId, ':nota' => $nota, ':por' => usuario_actual(), ':en' => gmdate('Y-m-d\TH:i:s\Z')]);

    return true;
}

function guardar_perfil(string $disp, array $datos): void
{
    $lat = is_numeric($datos['latitud'] ?? null) ? (float) $datos['latitud'] : null;
    $lon = is_numeric($datos['longitud'] ?? null) ? (float) $datos['longitud'] : null;
    db()->prepare(
        'INSERT INTO device_profiles (device_id, display_name, location, latitude, longitude, notes, updated_at)
         VALUES (:d, :n, :u, :la, :lo, :no, :en)
         ON CONFLICT (device_id) DO UPDATE SET display_name = excluded.display_name, location = excluded.location,
            latitude = excluded.latitude, longitude = excluded.longitude, notes = excluded.notes, updated_at = excluded.updated_at',
    )->execute([
        ':d' => $disp,
        ':n' => mb_substr(trim((string) ($datos['nombre'] ?? '')), 0, 120) ?: null,
        ':u' => mb_substr(trim((string) ($datos['ubicacion'] ?? '')), 0, 200) ?: null,
        ':la' => $lat, ':lo' => $lon,
        ':no' => mb_substr(trim((string) ($datos['notas'] ?? '')), 0, 2000) ?: null,
        ':en' => gmdate('Y-m-d\TH:i:s\Z'),
    ]);
}
