<?php

declare(strict_types=1);

namespace Jocotoco\Tests\Unit;

use Jocotoco\Config\Config;
use Jocotoco\Enrollment\EnrollmentRepository;
use Jocotoco\Enrollment\EnrollmentService;
use Jocotoco\Enrollment\TokenIssuer;
use Jocotoco\Enrollment\TokenIssuerException;
use Jocotoco\Exception\HttpException;
use Jocotoco\Storage\Database;
use Jocotoco\Support\FrozenClock;
use Jocotoco\Support\Logger;
use PHPUnit\Framework\TestCase;

final class EnrollmentServiceTest extends TestCase
{
    private const SECRETO = 'sk_factory_9f83a02b11c';

    private Database $database;
    private FrozenClock $clock;

    protected function setUp(): void
    {
        $this->database = new Database(':memory:', dirname(__DIR__, 2) . '/database/migrations');
        $this->database->migrate();
        $this->clock = new FrozenClock(1758500000);
    }

    public function testEntregaUnTokenAlDispositivoConElSecretoCorrecto(): void
    {
        $resultado = $this->servicio()->enroll('rpi-yanacocha-01', self::SECRETO, '10.0.0.5');

        self::assertSame('cabecera.cuerpo.firma', $resultado['token']);
        self::assertSame('rpi-yanacocha-01', $resultado['device_id']);
        self::assertSame(1, $resultado['tokens_restantes']);
        self::assertSame('https://ca.example.org:8443', $resultado['ca_url']);
    }

    public function testRechazaUnSecretoIncorrecto(): void
    {
        try {
            $this->servicio()->enroll('rpi-yanacocha-01', 'otro-secreto-cualquiera', '10.0.0.5');
            self::fail('Se esperaba el rechazo.');
        } catch (HttpException $exception) {
            self::assertSame(403, $exception->status());
        }

        self::assertNull($this->repositorio()->find('rpi-yanacocha-01'));
    }

    public function testRechazaCuandoLaInscripcionEstaDeshabilitada(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionCode(404);

        $this->servicio(['enrollment_enabled' => false])->enroll('rpi-uno', self::SECRETO, '10.0.0.5');
    }

    public function testRechazaUnSecretoConfiguradoDemasiadoCorto(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionCode(503);

        $this->servicio(['enrollment_secret' => 'corto'])->enroll('rpi-uno', 'corto', '10.0.0.5');
    }

    public function testRechazaNombresQueNoSonHostname(): void
    {
        foreach (['RPI-Mayusculas', 'rpi con espacios', '../../etc/passwd', '', 'rpi_guion_bajo'] as $nombre) {
            try {
                $this->servicio()->enroll($nombre, self::SECRETO, '10.0.0.5');
                self::fail(sprintf('Se esperaba el rechazo de "%s".', $nombre));
            } catch (HttpException $exception) {
                self::assertSame(400, $exception->status(), $nombre);
            }
        }
    }

    public function testRespetaLaListaDeDispositivosAutorizados(): void
    {
        $servicio = $this->servicio(['enrollment_devices' => ['rpi-mindo-02']]);

        self::assertSame('cabecera.cuerpo.firma', $servicio->enroll('rpi-mindo-02', self::SECRETO, '10.0.0.5')['token']);

        $this->expectException(HttpException::class);
        $this->expectExceptionCode(403);
        $servicio->enroll('rpi-intruso-09', self::SECRETO, '10.0.0.5');
    }

    public function testAgotaElTopeDeTokensPorDispositivo(): void
    {
        $servicio = $this->servicio(['enrollment_max_tokens' => 2]);

        $servicio->enroll('rpi-uno', self::SECRETO, '10.0.0.5');
        $servicio->enroll('rpi-uno', self::SECRETO, '10.0.0.5');

        try {
            $servicio->enroll('rpi-uno', self::SECRETO, '10.0.0.5');
            self::fail('Se esperaba el rechazo por tope.');
        } catch (HttpException $exception) {
            self::assertSame(409, $exception->status());
        }

        self::assertSame(2, (int) $this->repositorio()->find('rpi-uno')['tokens_issued']);
    }

    public function testRechazaUnDispositivoBloqueado(): void
    {
        $this->repositorio()->block('rpi-perdida', 'equipo extraviado', $this->clock->nowIso8601());

        $this->expectException(HttpException::class);
        $this->expectExceptionCode(403);

        $this->servicio()->enroll('rpi-perdida', self::SECRETO, '10.0.0.5');
    }

    public function testLimitaLosIntentosFallidosPorIp(): void
    {
        $servicio = $this->servicio(['enrollment_max_failures_per_ip' => 3]);

        for ($i = 0; $i < 3; $i++) {
            try {
                $servicio->enroll('rpi-uno', 'secreto-equivocado-x', '10.0.0.9');
            } catch (HttpException) {
                // esperado
            }
        }

        try {
            $servicio->enroll('rpi-uno', self::SECRETO, '10.0.0.9');
            self::fail('Se esperaba el rechazo por limite de IP.');
        } catch (HttpException $exception) {
            self::assertSame(429, $exception->status(), 'Incluso con el secreto correcto, la IP queda frenada.');
        }
    }

    public function testDejaRastroDeCadaIntento(): void
    {
        $servicio = $this->servicio();
        $servicio->enroll('rpi-uno', self::SECRETO, '10.0.0.5');
        try {
            $servicio->enroll('rpi-dos', 'secreto-equivocado-x', '10.0.0.7');
        } catch (HttpException) {
            // esperado
        }

        $filas = $this->database->connection()
            ->query('SELECT device_id, ip, result FROM enrollment_attempts ORDER BY id')
            ->fetchAll();

        self::assertSame('emitido', $filas[0]['result']);
        self::assertSame('10.0.0.5', $filas[0]['ip']);
        self::assertSame('secreto-invalido', $filas[1]['result']);
        self::assertSame('rpi-dos', $filas[1]['device_id']);
    }

    public function testTraduceUnFalloDeLaCaAUnErrorInterno(): void
    {
        $servicio = $this->servicio([], new class implements TokenIssuer {
            public function issue(string $deviceId): string
            {
                throw new TokenIssuerException('step-ca no responde');
            }
        });

        try {
            $servicio->enroll('rpi-uno', self::SECRETO, '10.0.0.5');
            self::fail('Se esperaba un error interno.');
        } catch (HttpException $exception) {
            self::assertSame(500, $exception->status());
            self::assertStringNotContainsString('step-ca no responde', $exception->getMessage(),
                'El detalle interno no debe filtrarse al cliente.');
        }

        self::assertNull($this->repositorio()->find('rpi-uno'), 'Un fallo de la CA no consume token.');
    }

    private function repositorio(): EnrollmentRepository
    {
        return new EnrollmentRepository($this->database);
    }

    /** @param array<string,mixed> $overrides */
    private function servicio(array $overrides = [], ?TokenIssuer $issuer = null): EnrollmentService
    {
        $config = new Config(array_merge([
            'enrollment_enabled' => true,
            'enrollment_secret' => self::SECRETO,
            'enrollment_devices' => [],
            'enrollment_max_tokens' => 2,
            'enrollment_max_failures_per_ip' => 10,
            'enrollment_failure_window_seconds' => 3600,
            'enrollment_token_duration' => '60m',
            'ca_url' => 'https://ca.example.org:8443',
            'ca_root_fingerprint' => str_repeat('a', 64),
        ], $overrides));

        $issuer ??= new class implements TokenIssuer {
            public function issue(string $deviceId): string
            {
                return 'cabecera.cuerpo.firma';
            }
        };

        return new EnrollmentService(
            $config,
            $this->repositorio(),
            $issuer,
            $this->clock,
            new Logger('stderr', $this->clock, fopen('php://memory', 'wb')),
        );
    }
}
