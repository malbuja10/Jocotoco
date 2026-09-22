<?php

declare(strict_types=1);

namespace Jocotoco\Tests\Unit;

use Jocotoco\Exception\HttpException;
use Jocotoco\Http\Request;
use Jocotoco\Http\Response;
use Jocotoco\Http\Router;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    public function testResuelveUnaRutaConParametros(): void
    {
        $router = new Router();
        $router->get('/v1/grabaciones/{id}/audio', static fn (Request $r, array $p): Response => Response::text(200, $p['id']));

        $response = $router->dispatch(new Request('GET', '/v1/grabaciones/ABC123/audio'));

        self::assertSame(200, $response->status);
        self::assertSame('ABC123', $response->body);
    }

    public function testDevuelve404CuandoLaRutaNoExiste(): void
    {
        $router = new Router();
        $router->get('/v1/salud', static fn (): Response => Response::noContent());

        $this->expectException(HttpException::class);
        $this->expectExceptionCode(404);

        $router->dispatch(new Request('GET', '/v1/desconocida'));
    }

    public function testDevuelve405ConLosMetodosPermitidos(): void
    {
        $router = new Router();
        $router->get('/v1/grabaciones', static fn (): Response => Response::noContent());
        $router->post('/v1/grabaciones', static fn (): Response => Response::noContent());

        try {
            $router->dispatch(new Request('DELETE', '/v1/grabaciones'));
            self::fail('Se esperaba una HttpException 405.');
        } catch (HttpException $exception) {
            self::assertSame(405, $exception->status());
            self::assertSame(['GET', 'POST'], $exception->extra()['allow']);
        }
    }

    public function testLosParametrosNoAtraviesanSegmentos(): void
    {
        $router = new Router();
        $router->get('/v1/grabaciones/{id}', static fn (): Response => Response::noContent());

        $this->expectException(HttpException::class);

        $router->dispatch(new Request('GET', '/v1/grabaciones/uno/dos'));
    }
}
