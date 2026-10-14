<?php

declare(strict_types=1);

namespace Jocotoco\Tests\Integration;

use Jocotoco\Tests\Support\ApiTestCase;
use Jocotoco\Tests\Support\AudioFactory;

/**
 * Seccion 3 del contrato del monitor: POST /api/sincronizar con una parte
 * "data" (JSON serializado) y una parte "audio" (WAV binario).
 *
 * El monitor borra su copia local en cuanto recibe 200 o 201, asi que cada
 * prueba vigila dos cosas: que un exito signifique que el dato ya esta en
 * disco y en la base, y que todo lo demas falle con un codigo distinto.
 */
final class EventsApiTest extends ApiTestCase
{
    private const DISPOSITIVO = 'rpi-yanacocha-01';

    public function testGuardaElEventoYSuAudioConElPayloadDelContrato(): void
    {
        $audio = AudioFactory::wavPersonalizado(16000, 1, 16, 2.0);
        $marca = $this->clock->now() - 120;

        $response = $this->app->handle($this->multipart(
            '/api/sincronizar',
            $this->ca->clientPem(self::DISPOSITIVO),
            ['data' => json_encode([
                'event_id' => '7f4c1d62-9a84-4c3d-9d2e-1b0f5a6c7e81',
                'device_id' => self::DISPOSITIVO,
                'threat_type' => 'chainsaw',
                'confidence_score' => 0.87,
                'audio_path' => '/home/pi/monitor/audio/evento-2026-10-14.wav',
                'timestamp' => $marca,
                'duration_sec' => 2.0,
                'sample_rate' => 16000,
                'channels' => 1,
                'bit_depth' => 16,
            ], JSON_UNESCAPED_SLASHES)],
            $this->archivo($audio, 'evento-2026-10-14.wav'),
        ));

        $cuerpo = $this->decode($response);

        self::assertSame(201, $response->status, $response->body);
        self::assertSame('ok', $cuerpo['status']);
        self::assertFalse($cuerpo['duplicado']);
        self::assertSame('7f4c1d62-9a84-4c3d-9d2e-1b0f5a6c7e81', $cuerpo['evento']['event_id']);
        self::assertSame(self::DISPOSITIVO, $cuerpo['evento']['device_id']);
        self::assertSame('chainsaw', $cuerpo['evento']['threat_type']);
        self::assertSame(0.87, $cuerpo['evento']['confidence_score']);
        self::assertSame($marca, $cuerpo['evento']['timestamp']);
        self::assertSame(gmdate('Y-m-d\TH:i:s\Z', $marca), $cuerpo['evento']['ocurrido_en']);
        self::assertSame(2.0, $cuerpo['evento']['audio']['duration_sec']);
        self::assertSame(16000, $cuerpo['evento']['audio']['sample_rate']);
        self::assertSame(1, $cuerpo['evento']['audio']['channels']);
        self::assertSame(16, $cuerpo['evento']['audio']['bit_depth']);
        self::assertArrayHasKey('Location', $response->headers);

        // 201 significa que el audio ya esta en disco, byte a byte.
        $grabacion = $cuerpo['evento']['audio']['grabacion_id'];
        $ruta = $this->app->storage()->absolutePath(
            (string) $this->app->repository()->find($grabacion)?->relativePath,
        );
        self::assertFileExists($ruta);
        self::assertSame($audio, file_get_contents($ruta));

        // Y que se puede descargar por la ruta que anuncia la respuesta.
        $descarga = $this->app->handle($this->request(
            'GET',
            $cuerpo['evento']['audio']['descarga'],
            $this->ca->clientPem(self::DISPOSITIVO),
        ));
        self::assertSame(200, $descarga->status);
    }

    public function testElMismoEventoRepetidoDevuelve200YNoDuplica(): void
    {
        $primera = $this->enviarEvento(['event_id' => 'evento-repetido-0001']);
        $segunda = $this->enviarEvento(['event_id' => 'evento-repetido-0001']);

        $uno = $this->decode($primera);
        $dos = $this->decode($segunda);

        self::assertSame(201, $primera->status, $primera->body);
        self::assertSame(200, $segunda->status, $segunda->body);
        self::assertTrue($dos['duplicado']);
        self::assertSame('true', $segunda->headers['Idempotent-Replay']);
        self::assertSame($uno['evento']['id'], $dos['evento']['id'], 'El reintento devuelve el evento ya guardado.');

        self::assertSame(1, (int) $this->app->database()->connection()
            ->query('SELECT COUNT(*) FROM detection_events')->fetchColumn());
    }

    public function testDosDispositivosPuedenUsarElMismoEventId(): void
    {
        // La clave logica del contrato es device_id + event_id.
        $este = $this->enviarEvento(['event_id' => 'evento-compartido-01']);
        $otro = $this->enviarEvento(['event_id' => 'evento-compartido-01'], dispositivo: 'rpi-tapichalaca-02');

        self::assertSame(201, $este->status);
        self::assertSame(201, $otro->status, $otro->body);
        self::assertNotSame($this->decode($este)['evento']['id'], $this->decode($otro)['evento']['id']);
    }

    public function testDosEventosConElMismoAudioComparteLaGrabacion(): void
    {
        // El audio se deduplica por sha256 y el evento por event_id: dos
        // eventos distintos con el mismo WAV no guardan el archivo dos veces.
        $audio = AudioFactory::wavPersonalizado(16000, 1, 16, 1.0);

        $uno = $this->decode($this->enviarEvento(['event_id' => 'mismo-audio-0001'], audio: $audio));
        $dos = $this->decode($this->enviarEvento(['event_id' => 'mismo-audio-0002'], audio: $audio));

        self::assertNotSame($uno['evento']['id'], $dos['evento']['id']);
        self::assertSame(
            $uno['evento']['audio']['grabacion_id'],
            $dos['evento']['audio']['grabacion_id'],
        );
    }

    public function testRechazaUnEventoSinCertificado(): void
    {
        $response = $this->app->handle($this->multipart('/api/sincronizar', null, ['data' => '{}']));

        self::assertSame(401, $response->status);
        self::assertStringContainsString('application/problem+json', $response->headers['Content-Type']);
    }

    public function testRechazaUnEventoSinLaParteData(): void
    {
        $response = $this->app->handle($this->multipart(
            '/api/sincronizar',
            $this->ca->clientPem(self::DISPOSITIVO),
            [],
            $this->archivo(AudioFactory::wav()),
        ));

        self::assertSame(400, $response->status);
        self::assertStringContainsString('data', $this->decode($response)['detail']);
    }

    public function testRechazaUnaParteDataQueNoEsJson(): void
    {
        $response = $this->app->handle($this->multipart(
            '/api/sincronizar',
            $this->ca->clientPem(self::DISPOSITIVO),
            ['data' => 'no soy json'],
            $this->archivo(AudioFactory::wav()),
        ));

        self::assertSame(400, $response->status);
    }

    public function testRechazaUnEventoSinAudio(): void
    {
        $response = $this->app->handle($this->multipart(
            '/api/sincronizar',
            $this->ca->clientPem(self::DISPOSITIVO),
            ['data' => json_encode(['event_id' => 'evento-sin-audio-01'])],
        ));

        self::assertSame(400, $response->status);
        self::assertStringContainsString('audio', $this->decode($response)['detail']);
    }

    public function testRechazaUnEventIdQueNoCumpleElFormato(): void
    {
        foreach (['', 'corto', str_repeat('x', 65), 'con espacios aqui'] as $eventId) {
            $response = $this->app->handle($this->multipart(
                '/api/sincronizar',
                $this->ca->clientPem(self::DISPOSITIVO),
                ['data' => json_encode(['event_id' => $eventId])],
                $this->archivo(AudioFactory::wav()),
            ));

            self::assertSame(400, $response->status, sprintf('event_id "%s"', $eventId));
        }
    }

    public function testRechazaUnDeviceIdQueNoCorrespondeAlCertificado(): void
    {
        $response = $this->app->handle($this->multipart(
            '/api/sincronizar',
            $this->ca->clientPem(self::DISPOSITIVO),
            ['data' => json_encode([
                'event_id' => 'evento-suplantado-01',
                'device_id' => 'rpi-de-otro',
            ])],
            $this->archivo(AudioFactory::wav()),
        ));

        self::assertSame(403, $response->status);
        self::assertStringContainsString('certificado', $this->decode($response)['detail']);
    }

    public function testAceptaUnEventoSinDeviceIdYUsaElDelCertificado(): void
    {
        $cuerpo = $this->decode($this->enviarEvento(['event_id' => 'evento-sin-device-1'], conDeviceId: false));

        self::assertSame(self::DISPOSITIVO, $cuerpo['evento']['device_id']);
    }

    public function testRechazaUnArchivoQueNoEsWav(): void
    {
        $response = $this->app->handle($this->multipart(
            '/api/sincronizar',
            $this->ca->clientPem(self::DISPOSITIVO),
            ['data' => json_encode(['event_id' => 'evento-flac-00001'])],
            $this->archivo(AudioFactory::flac(), 'evento.flac'),
        ));

        self::assertSame(415, $response->status, $response->body);
    }

    public function testRechazaUnWavComprimido(): void
    {
        $response = $this->app->handle($this->multipart(
            '/api/sincronizar',
            $this->ca->clientPem(self::DISPOSITIVO),
            ['data' => json_encode(['event_id' => 'evento-adpcm-0001'])],
            $this->archivo(AudioFactory::wavPersonalizado(16000, 1, 16, 1.0, 2)),
        ));

        self::assertSame(415, $response->status, $response->body);
        self::assertStringContainsString('PCM', $this->decode($response)['detail']);
    }

    public function testRechazaUnAudioMasLargoQueElLimite(): void
    {
        $limite = $this->app->config()->int('event_max_duration_seconds');
        $audio = AudioFactory::wavPersonalizado(8000, 1, 8, $limite + 1.0);

        $response = $this->app->handle($this->multipart(
            '/api/sincronizar',
            $this->ca->clientPem(self::DISPOSITIVO),
            ['data' => json_encode(['event_id' => 'evento-muy-largo-1'])],
            $this->archivo($audio),
        ));

        self::assertSame(422, $response->status, $response->body);
        self::assertSame($limite, $this->decode($response)['maximo_sec']);
    }

    public function testRechazaUnaFrecuenciaDeMuestreoNoPermitida(): void
    {
        $response = $this->app->handle($this->multipart(
            '/api/sincronizar',
            $this->ca->clientPem(self::DISPOSITIVO),
            ['data' => json_encode(['event_id' => 'evento-12345hz-01'])],
            $this->archivo(AudioFactory::wavPersonalizado(12345, 1, 16, 1.0)),
        ));

        self::assertSame(422, $response->status, $response->body);
        self::assertSame(12345, $this->decode($response)['sample_rate']);
    }

    public function testRechazaMasCanalesDeLosPermitidos(): void
    {
        $response = $this->app->handle($this->multipart(
            '/api/sincronizar',
            $this->ca->clientPem(self::DISPOSITIVO),
            ['data' => json_encode(['event_id' => 'evento-4-canales-1'])],
            $this->archivo(AudioFactory::wavPersonalizado(16000, 4, 16, 1.0)),
        ));

        self::assertSame(422, $response->status, $response->body);
    }

    public function testGuardaLoQueDiceElWavCuandoElJsonDeclaraOtraCosa(): void
    {
        // El contrato advierte que esos valores los calcula el monitor: el
        // evento se acepta, pero se guarda lo que contiene el archivo.
        $response = $this->enviarEvento(
            [
                'event_id' => 'evento-desajustado-1',
                'duration_sec' => 9.5,
                'sample_rate' => 44100,
                'channels' => 2,
                'bit_depth' => 24,
            ],
            audio: AudioFactory::wavPersonalizado(16000, 1, 16, 1.0),
        );

        $evento = $this->decode($response)['evento'];

        self::assertSame(201, $response->status);
        self::assertSame(1.0, $evento['audio']['duration_sec']);
        self::assertSame(16000, $evento['audio']['sample_rate']);
        self::assertSame(1, $evento['audio']['channels']);
        self::assertSame(16, $evento['audio']['bit_depth']);
    }

    public function testAceptaUnTimestampEnMilisegundos(): void
    {
        $segundos = $this->clock->now() - 300;

        $cuerpo = $this->decode($this->enviarEvento([
            'event_id' => 'evento-milisegundos',
            'timestamp' => $segundos * 1000,
        ]));

        self::assertSame($segundos, $cuerpo['evento']['timestamp']);
    }

    public function testUsaLaHoraDelServidorSiElEventoNoTraeTimestamp(): void
    {
        $cuerpo = $this->decode($this->enviarEvento(['event_id' => 'evento-sin-marca-1'], conTimestamp: false));

        self::assertSame($this->clock->now(), $cuerpo['evento']['timestamp']);
    }

    public function testRechazaUnTimestampImplausible(): void
    {
        foreach ([1, 946_684_799, $this->clock->now() + 86_400] as $marca) {
            $response = $this->enviarEvento([
                'event_id' => 'evento-fecha-' . abs($marca),
                'timestamp' => $marca,
            ]);

            self::assertSame(400, $response->status, sprintf('timestamp %d', $marca));
        }
    }

    public function testRechazaUnPuntajeQueNoEsNumerico(): void
    {
        $response = $this->enviarEvento([
            'event_id' => 'evento-puntaje-mal',
            'confidence_score' => 'muy alto',
        ]);

        self::assertSame(400, $response->status, $response->body);
    }

    public function testGuardaLiteralmenteLaClaseAunqueNoSeaUnaAmenaza(): void
    {
        // "threat_type" es la etiqueta del modelo, no una amenaza confirmada.
        $cuerpo = $this->decode($this->enviarEvento([
            'event_id' => 'evento-ruido-00001',
            'threat_type' => 'background_noise',
            'confidence_score' => 0.12,
        ]));

        self::assertSame('background_noise', $cuerpo['evento']['threat_type']);
        self::assertSame(0.12, $cuerpo['evento']['confidence_score']);
    }

    public function testConservaLaRutaLocalSoloComoReferencia(): void
    {
        $cuerpo = $this->decode($this->enviarEvento([
            'event_id' => 'evento-ruta-000001',
            'audio_path' => '/home/pi/monitor/audio/evento.wav',
        ]));

        $detalle = $this->decode($this->app->handle($this->request(
            'GET',
            '/v1/eventos/' . $cuerpo['evento']['id'],
            $this->ca->clientPem(self::DISPOSITIVO),
        )));

        self::assertSame('/home/pi/monitor/audio/evento.wav', $detalle['ruta_declarada_en_el_dispositivo']);
        self::assertSame('/home/pi/monitor/audio/evento.wav', $detalle['payload_original']['audio_path']);
        self::assertNotNull($detalle['evento']['audio']['grabacion_id'], 'El audio se guarda bajo su propio id.');
    }

    public function testCadaDispositivoSoloVeSusEventos(): void
    {
        $este = $this->decode($this->enviarEvento(['event_id' => 'evento-propio-0001']));
        $this->enviarEvento(['event_id' => 'evento-ajeno-00001'], dispositivo: 'rpi-tapichalaca-02');

        $listado = $this->decode($this->app->handle($this->request(
            'GET',
            '/v1/eventos',
            $this->ca->clientPem(self::DISPOSITIVO),
        )));

        self::assertSame(1, $listado['total']);
        self::assertSame('evento-propio-0001', $listado['eventos'][0]['event_id']);

        $ajeno = $this->app->handle($this->request(
            'GET',
            '/v1/eventos/' . $este['evento']['id'],
            $this->ca->clientPem('rpi-tapichalaca-02'),
        ));

        self::assertSame(403, $ajeno->status);
    }

    public function testFiltraElListadoPorClase(): void
    {
        $this->enviarEvento(['event_id' => 'evento-motosierra-1', 'threat_type' => 'chainsaw']);
        $this->enviarEvento(['event_id' => 'evento-disparo-0001', 'threat_type' => 'gunshot']);

        $listado = $this->decode($this->app->handle($this->request(
            'GET',
            '/v1/eventos',
            $this->ca->clientPem(self::DISPOSITIVO),
            query: ['clase' => 'gunshot'],
        )));

        self::assertCount(1, $listado['eventos']);
        self::assertSame('gunshot', $listado['eventos'][0]['threat_type']);
    }

    /**
     * Envia un evento valido, cambiando solo lo que pide la prueba.
     *
     * @param array<string,mixed> $datos
     */
    private function enviarEvento(
        array $datos,
        string $dispositivo = self::DISPOSITIVO,
        ?string $audio = null,
        bool $conDeviceId = true,
        bool $conTimestamp = true,
    ): \Jocotoco\Http\Response {
        $base = [
            'threat_type' => 'chainsaw',
            'confidence_score' => 0.91,
            'audio_path' => '/home/pi/monitor/audio/evento.wav',
            'duration_sec' => 1.0,
            'sample_rate' => 16000,
            'channels' => 1,
            'bit_depth' => 16,
        ];

        if ($conDeviceId) {
            $base['device_id'] = $dispositivo;
        }

        if ($conTimestamp && !array_key_exists('timestamp', $datos)) {
            $base['timestamp'] = $this->clock->now() - 30;
        }

        // Un WAV distinto por envio: asi el sha256 no deduplica la grabacion.
        $contenido = $audio ?? AudioFactory::wavPersonalizado(16000, 1, 16, 1.0)
            . pack('V', random_int(0, PHP_INT_MAX >> 32));

        return $this->app->handle($this->multipart(
            '/api/sincronizar',
            $this->ca->clientPem($dispositivo),
            ['data' => json_encode(array_merge($base, $datos), JSON_UNESCAPED_SLASHES)],
            $this->archivo($contenido),
        ));
    }

    /** Escribe el audio en un archivo nuevo, como hace el servidor web con la parte subida. */
    private function archivo(string $contenido, string $nombre = 'evento.wav'): string
    {
        return AudioFactory::writeTo(
            $this->tmp->child('subidas'),
            bin2hex(random_bytes(4)) . '-' . $nombre,
            $contenido,
        );
    }
}
