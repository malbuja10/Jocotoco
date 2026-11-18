<?php

declare(strict_types=1);

/**
 * Arranque del panel web de monitoreo.
 *
 * El panel lee la misma base que el API (PostgreSQL en produccion) con la
 * misma configuracion (config/config.php + variables JOCOTOCO_*), pero corre
 * en su propio pool de php-fpm, con su propio usuario y su propio rol de
 * base de datos: puede leer todo y solo escribe sus dos tablas
 * (device_profiles y event_reviews).
 */

use Jocotoco\Application;
use Jocotoco\Config\Config;

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/lib/vista.php';
require __DIR__ . '/lib/consultas.php';

function cfg(): Config
{
    static $config = null;
    if ($config === null) {
        $defaults = require dirname(__DIR__) . '/config/config.php';
        $config = Config::fromEnvironment($defaults);
        $dataDir = rtrim($config->string('data_dir'), '/');
        $config = $config->with([
            'audio_dir' => (string) ($config->get('audio_dir') ?: $dataDir . '/audio'),
            'db_path' => (string) ($config->get('db_path') ?: $dataDir . '/jocotoco.sqlite'),
        ]);
    }

    return $config;
}

function cfg_float(string $clave): float
{
    return (float) cfg()->get($clave);
}

function db(): PDO
{
    static $pdo = null;

    return $pdo ??= Application::createDatabase(cfg(), dirname(__DIR__))->connection();
}

date_default_timezone_set((string) cfg()->get('panel_timezone') ?: 'UTC');

if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
    session_name('jocotoco_panel');
    session_start([
        'cookie_httponly' => true,
        'cookie_secure' => !empty($_SERVER['HTTPS']),
        'cookie_samesite' => 'Strict',
    ]);
}
