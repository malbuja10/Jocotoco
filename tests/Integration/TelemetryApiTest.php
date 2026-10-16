<?php

declare(strict_types=1);

namespace Jocotoco\Tests\Integration;

use Jocotoco\Tests\Support\ApiTestCase;

/**
 * Seccion 4 del contrato del monitor: POST /api/telemetry/voltage y
 * POST /api/telemetry/status, ambos con JSON y una lista de registros.
 *
 * El monitor borra de su base local los registros que sincroniza en cuanto
 * recibe 200, asi que un lote con un registro invalido se rechaza entero:
 * aceptar a medias le haria perder lo que el servidor descarto.
 */
final class TelemetryApiTest extends ApiTestCase
{
    private const DISPOSITIVO = 'rpi-yanacocha-01';

    public function testGuardaElVoltajeConElPayloadDelContrato(): void
    {
        $marca = $this->clock->now() - 600;

        $response = $this->app->handle($this->json(
            '/api/telemetry/voltage',
            $this->ca->clientPem(self::DISPOSITIVO),
            ['voltage' => [
                ['timestamp' => $marca, 'value' => 12.4, 'device_id' => self::DISPOSITIVO],
                ['timestamp' => $marca + 300, 'value' => 12.1, 'device_id' => self::DISPOSITIVO],
            ]],
        ));

        $cuerpo = $this->decode($response);

        self::assertSame(200, $response->status, $response->body);
        self::assertSame('ok', $cuerpo['status']);
        self::assertSame(2, $cuerpo['recibidas']);
        self::assertSame(2, $cuerpo['guardadas']);
        self::assertSame(0, $cuerpo['duplicadas']);

        $filas = $this->app->database()->connection()
            ->query('SELECT measured_at, value FROM voltage_readings ORDER BY measured_at')
            ->fetchAll(\PDO::FETCH_ASSOC);

        self::assertCount(2, $filas);
        self::assertSame($marca, (int) $filas[0]['measured_at']);
        self::assertSame(12.4, (float) $filas[0]['value']);
    }

    public function testGuardaElEstadoDelDispositivoConElPayloadDelContrato(): void
    {
        $marca = $this->clock->now() - 60;

        $response = $this->app->handle($this->json(
            '/api/telemetry/status',
            $this->ca->clientPem(self::DISPOSITIVO),
            ['device_telemetry' => [[
                'timestamp' => $marca,
                'cpu_temp' => 48.2,
                'cpu_usage_pct' => 14.5,
                'ram_usage_pct' => 37.1,
                'disk_free_gb' => 21.8,
                'disk_usage_pct' => 32.0,
                'uptime_hours' => 76.4,
                'device_id' => self::DISPOSITIVO,
            ]]],
        ));

        self::assertSame(200, $response->status, $response->body);
        self::assertSame(1, $this->decode($response)['guardadas']);

        $ultimo = $this->decode($this->app->handle($this->request(
            'GET',
            '/v1/telemetria',
            $this->ca->clientPem(self::DISPOSITIVO),
        )))['estado'][0];

        self::assertSame($marca, (int) $ultimo['measured_at']);
        self::assertSame(48.2, (float) $ultimo['cpu_temp'], 'La temperatura se guarda en grados Celsius.');
        self::assertSame(14.5, (float) $ultimo['cpu_usage_pct']);
        self::assertSame(37.1, (float) $ultimo['ram_usage_pct']);
        self::assertSame(21.8, (float) $ultimo['disk_free_gb']);
        self::assertSame(32.0, (float) $ultimo['disk_usage_pct']);
        self::assertSame(76.4, (float) $ultimo['uptime_hours']);
    }

    public function testReenviarElMismoLoteNoDuplicaRegistros(): void
    {
        // La clave de deduplicacion acordada es dispositivo + instante.
        $lote = ['voltage' => [
            ['timestamp' => $this->clock->now() - 120, 'value' => 12.4, 'device_id' => self::DISPOSITIVO],
        ]];

        $primera = $this->decode($this->app->handle(
            $this->json('/api/telemetry/voltage', $this->ca->clientPem(self::DISPOSITIVO), $lote),
        ));
        $segunda = $this->app->handle(
            $this->json('/api/telemetry/voltage', $this->ca->clientPem(self::DISPOSITIVO), $lote),
        );

        self::assertSame(1, $primera['guardadas']);
        self::assertSame(200, $segunda->status, 'El reintento tambien es un exito para el monitor.');
        self::assertSame(0, $this->decode($segunda)['guardadas']);
        self::assertSame(1, $this->decode($segunda)['duplicadas']);

        self::assertSame(1, (int) $this->app->database()->connection()
            ->query('SELECT COUNT(*) FROM voltage_readings')->fetchColumn());
    }

    public function testGuardaDosLecturasDistintasDelMismoSegundo(): void
    {
        // El monitor sella cada medida con int(time.time()): dos lecturas
        // seguidas pueden caer en el mismo segundo y las dos son reales.
        $marca = $this->clock->now() - 30;

        $cuerpo = $this->decode($this->app->handle($this->json(
            '/api/telemetry/voltage',
            $this->ca->clientPem(self::DISPOSITIVO),
            ['voltage' => [
                ['timestamp' => $marca, 'value' => 12.42],
                ['timestamp' => $marca, 'value' => 12.11],
            ]],
        )));

        self::assertSame(2, $cuerpo['guardadas'], 'Ninguna medida se pierde por compartir el segundo.');
        self::assertSame(0, $cuerpo['duplicadas']);
        self::assertSame(2, (int) $this->app->database()->connection()
            ->query('SELECT COUNT(*) FROM voltage_readings')->fetchColumn());
    }

    public function testElEstadoRepetidoSeDescartaPeroElDistintoNo(): void
    {
        $marca = $this->clock->now() - 30;
        $registro = [
            'timestamp' => $marca,
            'cpu_temp' => 48.2,
            'cpu_usage_pct' => 14.5,
            'ram_usage_pct' => 37.1,
            'disk_free_gb' => 21.8,
            'disk_usage_pct' => 32.0,
            'uptime_hours' => 76.4,
        ];

        $cuerpo = $this->decode($this->app->handle($this->json(
            '/api/telemetry/status',
            $this->ca->clientPem(self::DISPOSITIVO),
            ['device_telemetry' => [
                $registro,
                $registro,                                    // reenvio exacto
                array_merge($registro, ['cpu_temp' => 49.9]),  // otra medida
            ]],
        )));

        self::assertSame(2, $cuerpo['guardadas']);
        self::assertSame(1, $cuerpo['duplicadas']);
    }

    public function testAceptaUnTimestampIso8601(): void
    {
        $marca = $this->clock->now() - 120;

        $response = $this->app->handle($this->json(
            '/api/telemetry/voltage',
            $this->ca->clientPem(self::DISPOSITIVO),
            ['voltage' => [['timestamp' => gmdate('Y-m-d\TH:i:s\Z', $marca), 'value' => 12.4]]],
        ));

        self::assertSame(200, $response->status, $response->body);
        self::assertSame($marca, (int) $this->app->database()->connection()
            ->query('SELECT measured_at FROM voltage_readings')->fetchColumn());
    }

    public function testCadaDispositivoGuardaSuPropiaTelemetria(): void
    {
        $marca = $this->clock->now() - 90;
        $lote = ['voltage' => [['timestamp' => $marca, 'value' => 12.4]]];

        $this->app->handle($this->json('/api/telemetry/voltage', $this->ca->clientPem(self::DISPOSITIVO), $lote));
        $this->app->handle($this->json('/api/telemetry/voltage', $this->ca->clientPem('rpi-tapichalaca-02'), $lote));

        $mio = $this->decode($this->app->handle($this->request(
            'GET',
            '/v1/telemetria',
            $this->ca->clientPem(self::DISPOSITIVO),
        )));

        self::assertCount(1, $mio['voltaje']);
        self::assertSame(self::DISPOSITIVO, $mio['dispositivo']);
        self::assertSame(2, (int) $this->app->database()->connection()
            ->query('SELECT COUNT(*) FROM voltage_readings')->fetchColumn());
    }

    public function testRechazaElLoteCompletoSiUnRegistroEsInvalido(): void
    {
        $marca = $this->clock->now() - 60;

        $response = $this->app->handle($this->json(
            '/api/telemetry/voltage',
            $this->ca->clientPem(self::DISPOSITIVO),
            ['voltage' => [
                ['timestamp' => $marca, 'value' => 12.4],
                ['timestamp' => $marca + 60, 'value' => 99999.0],
            ]],
        ));

        self::assertSame(422, $response->status, $response->body);
        self::assertSame(0, (int) $this->app->database()->connection()
            ->query('SELECT COUNT(*) FROM voltage_readings')->fetchColumn(),
            'Si el servidor no guarda todo, no debe responder un exito ni guardar a medias.');
    }

    public function testRechazaValoresFueraDeRango(): void
    {
        $casos = [
            ['cpu_temp' => 400.0],
            ['cpu_usage_pct' => 150.0],
            ['ram_usage_pct' => -3.0],
            ['disk_usage_pct' => 101.0],
            ['uptime_hours' => -1.0],
        ];

        foreach ($casos as $indice => $extra) {
            $response = $this->app->handle($this->json(
                '/api/telemetry/status',
                $this->ca->clientPem(self::DISPOSITIVO),
                ['device_telemetry' => [array_merge(
                    ['timestamp' => $this->clock->now() - $indice - 1],
                    $extra,
                )]],
            ));

            self::assertSame(422, $response->status, json_encode($extra));
        }
    }

    public function testAceptaUnEstadoConCamposOpcionalesAusentes(): void
    {
        $response = $this->app->handle($this->json(
            '/api/telemetry/status',
            $this->ca->clientPem(self::DISPOSITIVO),
            ['device_telemetry' => [['timestamp' => $this->clock->now() - 10, 'cpu_temp' => 51.0]]],
        ));

        self::assertSame(200, $response->status, $response->body);

        $fila = $this->decode($this->app->handle($this->request(
            'GET',
            '/v1/telemetria',
            $this->ca->clientPem(self::DISPOSITIVO),
        )))['estado'][0];

        self::assertSame(51.0, (float) $fila['cpu_temp']);
        self::assertNull($fila['ram_usage_pct']);
        self::assertNull($fila['uptime_hours']);
    }

    public function testRechazaUnVoltajeSinValor(): void
    {
        $response = $this->app->handle($this->json(
            '/api/telemetry/voltage',
            $this->ca->clientPem(self::DISPOSITIVO),
            ['voltage' => [['timestamp' => $this->clock->now() - 10]]],
        ));

        self::assertSame(400, $response->status, $response->body);
    }

    public function testRechazaUnLoteSinLaClaveEsperada(): void
    {
        $response = $this->app->handle($this->json(
            '/api/telemetry/voltage',
            $this->ca->clientPem(self::DISPOSITIVO),
            ['lecturas' => [['timestamp' => $this->clock->now(), 'value' => 12.4]]],
        ));

        self::assertSame(400, $response->status);
        self::assertStringContainsString('voltage', $this->decode($response)['detail']);
    }

    public function testRechazaUnaListaVaciaOMalFormada(): void
    {
        foreach ([[], ['0' => 'x'], 'texto'] as $lista) {
            $response = $this->app->handle($this->json(
                '/api/telemetry/status',
                $this->ca->clientPem(self::DISPOSITIVO),
                ['device_telemetry' => $lista],
            ));

            self::assertSame(400, $response->status, json_encode($lista));
        }
    }

    public function testRechazaUnCuerpoQueNoEsJson(): void
    {
        $archivo = $this->tmp->child('cuerpo.txt');
        file_put_contents($archivo, 'esto no es json');

        $response = $this->app->handle($this->request(
            'POST',
            '/api/telemetry/voltage',
            $this->ca->clientPem(self::DISPOSITIVO),
            ['Content-Type' => 'application/json'],
            $archivo,
        ));

        self::assertSame(400, $response->status);
    }

    public function testRechazaUnTimestampAusenteOImplausible(): void
    {
        $casos = [
            ['value' => 12.4],
            ['timestamp' => 'ayer', 'value' => 12.4],
            ['timestamp' => 1, 'value' => 12.4],
            ['timestamp' => $this->clock->now() + 86_400, 'value' => 12.4],
        ];

        foreach ($casos as $registro) {
            $response = $this->app->handle($this->json(
                '/api/telemetry/voltage',
                $this->ca->clientPem(self::DISPOSITIVO),
                ['voltage' => [$registro]],
            ));

            self::assertSame(400, $response->status, json_encode($registro));
        }
    }

    public function testAceptaTimestampsEnMilisegundos(): void
    {
        $segundos = $this->clock->now() - 45;

        $response = $this->app->handle($this->json(
            '/api/telemetry/voltage',
            $this->ca->clientPem(self::DISPOSITIVO),
            ['voltage' => [['timestamp' => $segundos * 1000, 'value' => 12.4]]],
        ));

        self::assertSame(200, $response->status, $response->body);
        self::assertSame($segundos, (int) $this->app->database()->connection()
            ->query('SELECT measured_at FROM voltage_readings')->fetchColumn());
    }

    public function testRechazaUnRegistroDeOtroDispositivo(): void
    {
        $response = $this->app->handle($this->json(
            '/api/telemetry/voltage',
            $this->ca->clientPem(self::DISPOSITIVO),
            ['voltage' => [
                ['timestamp' => $this->clock->now() - 10, 'value' => 12.4, 'device_id' => self::DISPOSITIVO],
                ['timestamp' => $this->clock->now() - 20, 'value' => 12.2, 'device_id' => 'rpi-de-otro'],
            ]],
        ));

        self::assertSame(403, $response->status, $response->body);
        self::assertSame(0, (int) $this->app->database()->connection()
            ->query('SELECT COUNT(*) FROM voltage_readings')->fetchColumn());
    }

    public function testRechazaUnLoteConDemasiadosRegistros(): void
    {
        $app = $this->createApplication(['telemetry_max_records' => 5]);
        $app->prepare();

        $registros = [];
        for ($i = 0; $i < 6; $i++) {
            $registros[] = ['timestamp' => $this->clock->now() - $i - 1, 'value' => 12.4];
        }

        $response = $app->handle($this->json(
            '/api/telemetry/voltage',
            $this->ca->clientPem(self::DISPOSITIVO),
            ['voltage' => $registros],
        ));

        self::assertSame(413, $response->status, $response->body);
        self::assertSame(5, $this->decode($response)['maximo']);
    }

    public function testRechazaUnCuerpoDemasiadoGrande(): void
    {
        $app = $this->createApplication(['telemetry_max_body_bytes' => 256]);
        $app->prepare();

        $registros = [];
        for ($i = 0; $i < 50; $i++) {
            $registros[] = ['timestamp' => $this->clock->now() - $i - 1, 'value' => 12.4];
        }

        $response = $app->handle($this->json(
            '/api/telemetry/voltage',
            $this->ca->clientPem(self::DISPOSITIVO),
            ['voltage' => $registros],
        ));

        self::assertSame(413, $response->status, $response->body);
    }

    public function testRechazaTelemetriaSinCertificado(): void
    {
        foreach (['/api/telemetry/voltage', '/api/telemetry/status'] as $ruta) {
            $response = $this->app->handle($this->json($ruta, null, ['voltage' => []]));

            self::assertSame(401, $response->status, $ruta);
            self::assertStringContainsString('application/problem+json', $response->headers['Content-Type']);
        }
    }
}
