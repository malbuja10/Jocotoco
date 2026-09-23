<?php

declare(strict_types=1);

namespace Jocotoco\Tests\Integration;

use Jocotoco\Application;
use Jocotoco\Http\Request;
use Jocotoco\Tests\Support\ApiTestCase;

/**
 * Recorrido del alta automatica tal como la usa el cliente Python del
 * dispositivo: POST con JSON, sin certificado de cliente, y un emisor de
 * tokens real ejecutado por proc_open.
 */
final class EnrollmentApiTest extends ApiTestCase
{
    private const SECRETO = 'sk_factory_9f83a02b11c';

    /** Emisor de prueba: un script que devuelve un token con forma de JWT. */
    private function emisor(string $salida = 'cabecera.cuerpo.firma', int $codigo = 0): string
    {
        // Nombre unico por llamada: los argumentos de appConInscripcion() se
        // evaluan antes que su arreglo de valores por defecto, asi que un
        // nombre fijo haria que el emisor "feliz" sobreescribiera al de prueba.
        $ruta = $this->tmp->child('emitir-token-' . bin2hex(random_bytes(4)) . '.sh');
        file_put_contents($ruta, sprintf("#!/bin/sh\necho '%s'\nexit %d\n", $salida, $codigo));
        chmod($ruta, 0o755);

        return $ruta;
    }

    /** @param array<string,mixed> $overrides */
    private function appConInscripcion(array $overrides = []): Application
    {
        $app = $this->createApplication(array_merge([
            'enrollment_enabled' => true,
            'enrollment_secret' => self::SECRETO,
            'enrollment_token_command' => $this->emisor(),
            'ca_url' => 'https://ca.example.org:8443',
            'ca_root_fingerprint' => str_repeat('b', 64),
        ], $overrides));
        $app->prepare();

        return $app;
    }

    /** @param array<string,mixed> $cuerpo */
    private function peticion(array $cuerpo, string $ip = '10.0.0.5'): Request
    {
        $archivo = $this->tmp->child('cuerpo-' . bin2hex(random_bytes(4)) . '.json');
        file_put_contents($archivo, json_encode($cuerpo));
        $handle = fopen($archivo, 'rb');

        return new Request(
            'POST',
            '/v1/inscripcion',
            ['content-type' => 'application/json'],
            [],
            ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $ip],
            [],
            $handle === false ? null : $handle,
        );
    }

    public function testElDispositivoObtieneSuTokenSinCertificadoDeCliente(): void
    {
        $app = $this->appConInscripcion();

        $response = $app->handle($this->peticion([
            'device_id' => 'rpi-yanacocha-01',
            'factory_secret' => self::SECRETO,
        ]));

        $cuerpo = $this->decode($response);

        self::assertSame(200, $response->status, $response->body);
        self::assertSame('cabecera.cuerpo.firma', $cuerpo['token']);
        self::assertSame('rpi-yanacocha-01', $cuerpo['device_id']);
        self::assertSame('https://ca.example.org:8443', $cuerpo['ca_url']);
        self::assertSame('60m', $cuerpo['expira_en']);
    }

    public function testLaInscripcionEstaDeshabilitadaPorDefecto(): void
    {
        $response = $this->app->handle($this->peticion([
            'device_id' => 'rpi-yanacocha-01',
            'factory_secret' => self::SECRETO,
        ]));

        self::assertSame(404, $response->status);
    }

    public function testRechazaElSecretoIncorrectoSinRevelarNada(): void
    {
        $app = $this->appConInscripcion();

        $response = $app->handle($this->peticion([
            'device_id' => 'rpi-yanacocha-01',
            'factory_secret' => 'no-es-el-secreto',
        ]));

        self::assertSame(403, $response->status);
        self::assertStringNotContainsString(self::SECRETO, $response->body);
    }

    public function testRechazaUnCuerpoQueNoEsJson(): void
    {
        $app = $this->appConInscripcion();
        $archivo = $this->tmp->child('basura.bin');
        file_put_contents($archivo, 'esto no es json');
        $handle = fopen($archivo, 'rb');

        $request = new Request(
            'POST',
            '/v1/inscripcion',
            ['content-type' => 'application/json'],
            [],
            ['REMOTE_ADDR' => '10.0.0.5'],
            [],
            $handle === false ? null : $handle,
        );

        self::assertSame(400, $app->handle($request)->status);
    }

    public function testInformaUnErrorInternoSiLaCaFalla(): void
    {
        $app = $this->appConInscripcion([
            'enrollment_token_command' => $this->emisor('error: la CA no responde', 1),
        ]);

        $response = $app->handle($this->peticion([
            'device_id' => 'rpi-yanacocha-01',
            'factory_secret' => self::SECRETO,
        ]));

        self::assertSame(500, $response->status);
        self::assertStringNotContainsString('la CA no responde', $response->body);
    }

    public function testRechazaUnaSalidaQueNoEsUnToken(): void
    {
        $app = $this->appConInscripcion([
            'enrollment_token_command' => $this->emisor('Usage: jocotoco-emitir-token <nombre>', 0),
        ]);

        $response = $app->handle($this->peticion([
            'device_id' => 'rpi-yanacocha-01',
            'factory_secret' => self::SECRETO,
        ]));

        self::assertSame(500, $response->status, 'Una ayuda de uso no puede pasar por token.');
    }

    public function testElMetodoGetNoEstaPermitido(): void
    {
        $app = $this->appConInscripcion();
        $response = $app->handle($this->request('GET', '/v1/inscripcion'));

        self::assertSame(405, $response->status);
        self::assertSame('POST', $response->headers['Allow']);
    }

}
