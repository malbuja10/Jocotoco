<?php

declare(strict_types=1);

namespace Jocotoco\Http;

use Jocotoco\Exception\HttpException;

/**
 * Enrutador minimo con parametros de ruta ({id}).
 */
final class Router
{
    /** @var list<array{method:string,regex:string,names:list<string>,handler:callable}> */
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler): void
    {
        $names = [];
        $regex = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/',
            static function (array $matches) use (&$names): string {
                $names[] = $matches[1];

                return '([^/]+)';
            },
            $pattern,
        ) ?? $pattern;

        $this->routes[] = [
            'method' => strtoupper($method),
            'regex' => '#^' . $regex . '$#',
            'names' => $names,
            'handler' => $handler,
        ];
    }

    public function get(string $pattern, callable $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, callable $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    public function delete(string $pattern, callable $handler): void
    {
        $this->add('DELETE', $pattern, $handler);
    }

    public function dispatch(Request $request): Response
    {
        $allowed = [];

        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $request->path(), $matches) !== 1) {
                continue;
            }

            if ($route['method'] !== $request->method()) {
                $allowed[] = $route['method'];
                continue;
            }

            $params = [];
            foreach ($route['names'] as $index => $name) {
                $params[$name] = $matches[$index + 1] ?? '';
            }

            return ($route['handler'])($request, $params);
        }

        if ($allowed !== []) {
            $allowed = array_values(array_unique($allowed));

            throw HttpException::methodNotAllowed(
                sprintf('El metodo %s no esta permitido en %s.', $request->method(), $request->path()),
                $allowed,
            );
        }

        throw HttpException::notFound(sprintf('La ruta %s no existe.', $request->path()));
    }
}
