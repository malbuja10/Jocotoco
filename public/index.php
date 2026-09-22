<?php

declare(strict_types=1);

/**
 * Punto de entrada del API. Todas las peticiones llegan aqui desde Apache
 * (AliasMatch -> index.php, servido por php-fpm via mod_proxy_fcgi).
 */

use Jocotoco\Application;
use Jocotoco\Http\Request;
use Jocotoco\Http\Response;

require dirname(__DIR__) . '/vendor/autoload.php';

// El cuerpo se lee como stream desde la aplicacion; no se necesita salida
// intermedia ni conversion de errores a HTML.
ini_set('display_errors', '0');
ini_set('html_errors', '0');

try {
    $app = Application::create();
    $request = Request::fromGlobals($_SERVER, $_FILES, $_POST);
    $app->handle($request)->send();
} catch (Throwable $exception) {
    error_log(sprintf('[jocotoco] fallo de arranque: %s', $exception->getMessage()));
    Response::problem(
        500,
        'Error interno',
        'El API no pudo inicializarse. Revise el log del servidor.',
    )->send();
}
