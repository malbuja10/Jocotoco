<?php

declare(strict_types=1);

namespace Jocotoco\Tests\Integration;

use Jocotoco\Http\Request;
use Jocotoco\Http\UploadedFile;
use Jocotoco\Tests\Support\AudioFactory;
use Jocotoco\Tests\Support\CertificateFactory;
use Jocotoco\Tests\Support\ApiTestCase;

/**
 * Recorrido completo del API tal como lo usa la Raspberry:
 * envio binario con curl --cert/--key, consulta y descarga.
 */
final class RecordingsApiTest extends ApiTestCase
{
    public function testLaSondaDeSaludNoExigeCertificado(): void
    {
        $response = $this->app->handle($this->request('GET', '/v1/salud'));
        $cuerpo = $this->decode($response);

        self::assertSame(200, $response->status);
        self::assertSame('ok', $cuerpo['estado']);
        self::assertSame('ok', $cuerpo['comprobaciones']['base_datos']);
        self::assertSame('ok', $cuerpo['comprobaciones']['almacenamiento']);
    }

    public function testRechazaUnEnvioSinCertificado(): void
    {
        $response = $this->app->handle($this->request('POST', '/v1/grabaciones'));

        self::assertSame(401, $response->status);
        self::assertStringContainsString('application/problem+json', $response->headers['Content-Type']);
        self::assertStringContainsString('step-ca', $this->decode($response)['detail']);
    }

    public function testRecibeUnaGrabacionEnviadaComoCuerpoBinario(): void
    {
        $audio = AudioFactory::wav();
        $archivo = AudioFactory::writeTo($this->tmp->path, 'envio.wav', $audio);

        $response = $this->app->handle($this->request(
            'POST',
            '/v1/grabaciones',
            $this->ca->clientPem('rpi-yanacocha-01'),
            [
                'Content-Type' => 'audio/wav',
                'X-Audio-SHA256' => hash('sha256', $audio),
                'X-Grabado-En' => '2025-09-21T23:00:00Z',
                'X-Nombre-Archivo' => 'yanacocha-2025-09-21.wav',
                'X-Metadatos' => json_encode(['sitio' => 'Reserva Yanacocha', 'estacion' => 'E-07', 'latitud' => -0.1236]),
            ],
            $archivo,
        ));

        $cuerpo = $this->decode($response);

        self::assertSame(201, $response->status, $response->body);
        self::assertFalse($cuerpo['duplicado']);
        self::assertSame('rpi-yanacocha-01', $cuerpo['grabacion']['dispositivo']);
        self::assertSame('wav', $cuerpo['grabacion']['formato']);
        self::assertSame(strlen($audio), $cuerpo['grabacion']['tamano_bytes']);
        self::assertSame(hash('sha256', $audio), $cuerpo['grabacion']['sha256']);
        self::assertSame('2025-09-21T23:00:00Z', $cuerpo['grabacion']['grabado_en']);
        self::assertSame('Reserva Yanacocha', $cuerpo['grabacion']['metadatos']['sitio']);
        self::assertSame(-0.1236, $cuerpo['grabacion']['metadatos']['latitud']);
        self::assertArrayHasKey('Location', $response->headers);

        // El audio quedo en disco bajo el arbol del dispositivo.
        $ruta = $this->app->storage()->absolutePath(
            (string) $this->app->repository()->find($cuerpo['grabacion']['id'])?->relativePath,
        );
        self::assertFileExists($ruta);
        self::assertSame($audio, file_get_contents($ruta));
    }

    public function testUnReenvioDelMismoAudioNoDuplicaLaGrabacion(): void
    {
        $audio = AudioFactory::wav();
        $pem = $this->ca->clientPem('rpi-mindo-02');

        $primera = $this->decode($this->app->handle($this->envio($pem, $audio)));
        $response = $this->app->handle($this->envio($pem, $audio));
        $segunda = $this->decode($response);

        self::assertSame(200, $response->status);
        self::assertTrue($segunda['duplicado']);
        self::assertSame($primera['grabacion']['id'], $segunda['grabacion']['id']);
        self::assertSame('true', $response->headers['Idempotent-Replay']);
        self::assertSame(1, $this->app->repository()->countByDevice('rpi-mindo-02'));
    }

    public function testRechazaUnHashQueNoCoincide(): void
    {
        $audio = AudioFactory::wav();
        $archivo = AudioFactory::writeTo($this->tmp->path, 'envio.wav', $audio);

        $response = $this->app->handle($this->request(
            'POST',
            '/v1/grabaciones',
            $this->ca->clientPem('rpi-uno'),
            ['Content-Type' => 'audio/wav', 'X-Audio-SHA256' => str_repeat('a', 64)],
            $archivo,
        ));

        self::assertSame(422, $response->status);
        self::assertSame(0, $this->app->repository()->countByDevice('rpi-uno'));
    }

    public function testRechazaContenidoQueNoEsAudio(): void
    {
        $archivo = AudioFactory::writeTo($this->tmp->path, 'informe.pdf', AudioFactory::notAudio());

        $response = $this->app->handle($this->request(
            'POST',
            '/v1/grabaciones',
            $this->ca->clientPem('rpi-uno'),
            ['Content-Type' => 'audio/wav'],
            $archivo,
        ));

        self::assertSame(415, $response->status);
    }

    public function testRechazaUnAudioQueSuperaElLimite(): void
    {
        $this->app = $this->createApplication(['max_upload_bytes' => 4096]);
        $this->app->prepare();

        $archivo = AudioFactory::writeTo($this->tmp->path, 'grande.wav', AudioFactory::wav(20000));

        $response = $this->app->handle($this->request(
            'POST',
            '/v1/grabaciones',
            $this->ca->clientPem('rpi-uno'),
            ['Content-Type' => 'audio/wav'],
            $archivo,
        ));

        self::assertSame(413, $response->status);
        self::assertSame(4096, $this->decode($response)['maximo_bytes']);
    }

    public function testRechazaUnCuerpoVacio(): void
    {
        $archivo = AudioFactory::writeTo($this->tmp->path, 'vacio.wav', '');

        $response = $this->app->handle($this->request(
            'POST',
            '/v1/grabaciones',
            $this->ca->clientPem('rpi-uno'),
            ['Content-Type' => 'audio/wav'],
            $archivo,
        ));

        self::assertSame(400, $response->status);
    }

    public function testConsultaYDescargaLaGrabacionPropia(): void
    {
        $audio = AudioFactory::flac();
        $pem = $this->ca->clientPem('rpi-cuyabeno-03');
        $creada = $this->decode($this->app->handle($this->envio($pem, $audio, 'audio/flac')));
        $id = $creada['grabacion']['id'];

        $detalle = $this->app->handle($this->request('GET', '/v1/grabaciones/' . $id, $pem));
        self::assertSame(200, $detalle->status);
        self::assertSame('flac', $this->decode($detalle)['grabacion']['formato']);

        $descarga = $this->app->handle($this->request('GET', '/v1/grabaciones/' . $id . '/audio', $pem));
        self::assertSame(200, $descarga->status);
        self::assertSame('audio/flac', $descarga->headers['Content-Type']);
        self::assertSame((string) strlen($audio), $descarga->headers['Content-Length']);
        self::assertSame($audio, file_get_contents((string) $descarga->filePath));
    }

    public function testLaDescargaRespondeConNotModifiedSiElHashCoincide(): void
    {
        $audio = AudioFactory::wav();
        $pem = $this->ca->clientPem('rpi-uno');
        $id = $this->decode($this->app->handle($this->envio($pem, $audio)))['grabacion']['id'];

        $response = $this->app->handle($this->request(
            'GET',
            '/v1/grabaciones/' . $id . '/audio',
            $pem,
            ['If-None-Match' => '"' . hash('sha256', $audio) . '"'],
        ));

        self::assertSame(304, $response->status);
    }

    public function testUnDispositivoNoAccedeAGrabacionesDeOtro(): void
    {
        $propietario = $this->ca->clientPem('rpi-yanacocha-01');
        $ajeno = $this->ca->clientPem('rpi-intruso-09');
        $id = $this->decode($this->app->handle($this->envio($propietario, AudioFactory::wav())))['grabacion']['id'];

        $detalle = $this->app->handle($this->request('GET', '/v1/grabaciones/' . $id, $ajeno));
        self::assertSame(403, $detalle->status);

        $descarga = $this->app->handle($this->request('GET', '/v1/grabaciones/' . $id . '/audio', $ajeno));
        self::assertSame(403, $descarga->status);
    }

    public function testElListadoSoloMuestraLasGrabacionesDelDispositivo(): void
    {
        $uno = $this->ca->clientPem('rpi-uno');
        $dos = $this->ca->clientPem('rpi-dos');

        $this->app->handle($this->envio($uno, AudioFactory::wav(1000)));
        $this->app->handle($this->envio($uno, AudioFactory::wav(1100)));
        $this->app->handle($this->envio($dos, AudioFactory::wav(1200)));

        $listado = $this->decode($this->app->handle($this->request('GET', '/v1/grabaciones', $uno)));

        self::assertSame(2, $listado['total']);
        self::assertCount(2, $listado['grabaciones']);
        foreach ($listado['grabaciones'] as $grabacion) {
            self::assertSame('rpi-uno', $grabacion['dispositivo']);
        }
    }

    public function testUnClienteDeConsultaVeTodosLosDispositivos(): void
    {
        $this->app = $this->createApplication(['reader_devices' => ['panel-interno']]);
        $this->app->prepare();

        $this->app->handle($this->envio($this->ca->clientPem('rpi-uno'), AudioFactory::wav(1000)));
        $this->app->handle($this->envio($this->ca->clientPem('rpi-dos'), AudioFactory::wav(1100)));

        $panel = $this->ca->clientPem('panel-interno');

        $listado = $this->decode($this->app->handle($this->request('GET', '/v1/grabaciones', $panel)));
        self::assertSame(2, $listado['total']);

        $dispositivos = $this->decode($this->app->handle($this->request('GET', '/v1/dispositivos', $panel)));
        $ids = array_column($dispositivos['dispositivos'], 'id');
        self::assertContains('rpi-uno', $ids);
        self::assertContains('rpi-dos', $ids);
    }

    public function testUnDispositivoNormalNoPuedeListarDispositivos(): void
    {
        $response = $this->app->handle($this->request('GET', '/v1/dispositivos', $this->ca->clientPem('rpi-uno')));

        self::assertSame(403, $response->status);
    }

    public function testElBorradoEstaDeshabilitadoPorDefecto(): void
    {
        $pem = $this->ca->clientPem('rpi-uno');
        $id = $this->decode($this->app->handle($this->envio($pem, AudioFactory::wav())))['grabacion']['id'];

        $response = $this->app->handle($this->request('DELETE', '/v1/grabaciones/' . $id, $pem));

        self::assertSame(403, $response->status);
        self::assertNotNull($this->app->repository()->find($id));
    }

    public function testPermiteBorrarCuandoSeHabilita(): void
    {
        $this->app = $this->createApplication(['allow_delete' => true]);
        $this->app->prepare();

        $pem = $this->ca->clientPem('rpi-uno');
        $id = $this->decode($this->app->handle($this->envio($pem, AudioFactory::wav())))['grabacion']['id'];

        $response = $this->app->handle($this->request('DELETE', '/v1/grabaciones/' . $id, $pem));

        self::assertSame(204, $response->status);
        self::assertNull($this->app->repository()->find($id));
    }

    public function testDevuelve404ParaUnaGrabacionInexistente(): void
    {
        $response = $this->app->handle($this->request(
            'GET',
            '/v1/grabaciones/01K5ZZZZZZZZZZZZZZZZZZZZZZ',
            $this->ca->clientPem('rpi-uno'),
        ));

        self::assertSame(404, $response->status);
    }

    public function testDevuelve400ParaUnIdentificadorInvalido(): void
    {
        $response = $this->app->handle($this->request(
            'GET',
            '/v1/grabaciones/no-es-un-ulid',
            $this->ca->clientPem('rpi-uno'),
        ));

        self::assertSame(400, $response->status);
    }

    public function testInformaLaIdentidadDelDispositivo(): void
    {
        $pem = $this->ca->clientPem('rpi-yanacocha-01');
        $this->app->handle($this->envio($pem, AudioFactory::wav()));

        $cuerpo = $this->decode($this->app->handle($this->request('GET', '/v1/yo', $pem)));

        self::assertSame('rpi-yanacocha-01', $cuerpo['dispositivo']);
        self::assertGreaterThan(0, $cuerpo['expira_en_segundos']);
        self::assertSame(1, $cuerpo['historial']['grabaciones']);
    }

    public function testPublicaLosLimitesDeEnvio(): void
    {
        $cuerpo = $this->decode($this->app->handle($this->request('GET', '/v1/limites', $this->ca->clientPem('rpi-uno'))));

        self::assertSame(67108864, $cuerpo['maximo_bytes']);
        self::assertContains('wav', $cuerpo['formatos_permitidos']);
        self::assertSame('audio', $cuerpo['campo_multipart']);
    }

    public function testDevuelve405ConLaCabeceraAllow(): void
    {
        $response = $this->app->handle($this->request('DELETE', '/v1/salud', $this->ca->clientPem('rpi-uno')));

        self::assertSame(405, $response->status);
        self::assertSame('GET', $response->headers['Allow']);
    }

    public function testDevuelve404ParaRutasDesconocidas(): void
    {
        $response = $this->app->handle($this->request('GET', '/v1/ruta-inexistente'));

        self::assertSame(404, $response->status);
    }

    public function testCadaRespuestaLlevaCabecerasDeSeguridadYIdentificador(): void
    {
        $response = $this->app->handle($this->request('GET', '/v1/salud'));

        self::assertSame('nosniff', $response->headers['X-Content-Type-Options']);
        self::assertSame('no-store', $response->headers['Cache-Control']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{16}$/', $response->headers['X-Request-Id']);
    }

    public function testPropagaElIdentificadorDePeticionRecibido(): void
    {
        $response = $this->app->handle($this->request('GET', '/v1/salud', null, ['X-Request-Id' => 'peticion-123']));

        self::assertSame('peticion-123', $response->headers['X-Request-Id']);
    }

    public function testRecibeUnaGrabacionEnviadaComoMultipart(): void
    {
        $audio = AudioFactory::wav();
        $archivo = AudioFactory::writeTo($this->tmp->path, 'multipart.wav', $audio);
        $pem = $this->ca->clientPem('rpi-yanacocha-01');

        $request = new Request(
            'POST',
            '/v1/grabaciones',
            ['content-type' => 'multipart/form-data; boundary=----jocotoco'],
            [],
            [
                'SSL_CLIENT_VERIFY' => 'SUCCESS',
                'SSL_CLIENT_CERT' => CertificateFactory::escape($pem),
                'CONTENT_TYPE' => 'multipart/form-data; boundary=----jocotoco',
            ],
            [new UploadedFile('audio', $archivo, 'grabacion-nocturna.wav', 'audio/wav', strlen($audio))],
            null,
            ['grabado_en' => '2025-09-21T22:15:00Z', 'sitio' => 'Mindo', 'duracion_segundos' => '30'],
        );

        $response = $this->app->handle($request);
        $cuerpo = $this->decode($response);

        self::assertSame(201, $response->status, $response->body);
        self::assertSame('grabacion-nocturna.wav', $cuerpo['grabacion']['nombre_original']);
        self::assertSame('2025-09-21T22:15:00Z', $cuerpo['grabacion']['grabado_en']);
        self::assertSame('Mindo', $cuerpo['grabacion']['metadatos']['sitio']);
        self::assertSame(30, $cuerpo['grabacion']['metadatos']['duracion_segundos']);
    }

    public function testRechazaUnMultipartSinElCampoAudio(): void
    {
        $pem = $this->ca->clientPem('rpi-uno');

        $request = new Request(
            'POST',
            '/v1/grabaciones',
            ['content-type' => 'multipart/form-data; boundary=----jocotoco'],
            [],
            [
                'SSL_CLIENT_VERIFY' => 'SUCCESS',
                'SSL_CLIENT_CERT' => CertificateFactory::escape($pem),
                'CONTENT_TYPE' => 'multipart/form-data; boundary=----jocotoco',
            ],
            [new UploadedFile('adjunto', '/tmp/inexistente', 'x.wav', 'audio/wav', 10)],
        );

        $response = $this->app->handle($request);

        self::assertSame(400, $response->status);
        self::assertSame(['adjunto'], $this->decode($response)['campos_recibidos']);
    }

    public function testInformaCuandoElLimiteDePhpCortoLaSubida(): void
    {
        $pem = $this->ca->clientPem('rpi-uno');

        $request = new Request(
            'POST',
            '/v1/grabaciones',
            ['content-type' => 'multipart/form-data; boundary=----jocotoco'],
            [],
            [
                'SSL_CLIENT_VERIFY' => 'SUCCESS',
                'SSL_CLIENT_CERT' => CertificateFactory::escape($pem),
                'CONTENT_TYPE' => 'multipart/form-data; boundary=----jocotoco',
            ],
            [new UploadedFile('audio', '', 'x.wav', 'audio/wav', 0, UPLOAD_ERR_INI_SIZE)],
        );

        $response = $this->app->handle($request);

        self::assertSame(413, $response->status);
        self::assertStringContainsString('upload_max_filesize', $this->decode($response)['detail']);
    }

    private function envio(string $pem, string $audio, string $tipo = 'audio/wav'): Request
    {
        $archivo = AudioFactory::writeTo(
            $this->tmp->child('envios'),
            'envio-' . substr(hash('sha256', $audio), 0, 12) . '.bin',
            $audio,
        );

        return $this->request('POST', '/v1/grabaciones', $pem, ['Content-Type' => $tipo], $archivo);
    }
}
