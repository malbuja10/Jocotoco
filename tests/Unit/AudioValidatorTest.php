<?php

declare(strict_types=1);

namespace Jocotoco\Tests\Unit;

use Jocotoco\Audio\AudioSniffer;
use Jocotoco\Audio\AudioValidator;
use Jocotoco\Config\Config;
use Jocotoco\Exception\HttpException;
use Jocotoco\Tests\Support\AudioFactory;
use Jocotoco\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;

final class AudioValidatorTest extends TestCase
{
    private TemporaryDirectory $tmp;

    protected function setUp(): void
    {
        $this->tmp = new TemporaryDirectory();
    }

    protected function tearDown(): void
    {
        $this->tmp->remove();
    }

    public function testAceptaUnWavValido(): void
    {
        $path = AudioFactory::writeTo($this->tmp->path, 'a.wav', AudioFactory::wav());

        self::assertSame('wav', $this->validator()->validateFile($path, 'audio/wav')->name);
    }

    public function testAceptaOctetStreamComoTipoDeclarado(): void
    {
        $path = AudioFactory::writeTo($this->tmp->path, 'a.wav', AudioFactory::wav());

        self::assertSame('wav', $this->validator()->validateFile($path, 'application/octet-stream')->name);
    }

    public function testRechazaSiElTipoDeclaradoNoCoincideConElContenido(): void
    {
        $path = AudioFactory::writeTo($this->tmp->path, 'a.wav', AudioFactory::wav());

        $this->expectException(HttpException::class);
        $this->expectExceptionCode(415);

        $this->validator()->validateFile($path, 'audio/flac');
    }

    public function testRechazaContenidoQueNoEsAudio(): void
    {
        $path = AudioFactory::writeTo($this->tmp->path, 'informe.pdf', AudioFactory::notAudio());

        $this->expectException(HttpException::class);
        $this->expectExceptionCode(415);

        $this->validator()->validateFile($path);
    }

    public function testRechazaUnFormatoDeshabilitado(): void
    {
        $path = AudioFactory::writeTo($this->tmp->path, 'a.mp3', AudioFactory::mp3());

        $this->expectException(HttpException::class);
        $this->expectExceptionMessageMatches('/no esta habilitado/');

        $this->validator(['allowed_formats' => ['wav', 'flac']])->validateFile($path);
    }

    public function testRechazaArchivosDemasiadoPequenos(): void
    {
        $path = AudioFactory::writeTo($this->tmp->path, 'corto.wav', substr(AudioFactory::wav(), 0, 60));

        $this->expectException(HttpException::class);
        $this->expectExceptionCode(422);

        $this->validator()->validateFile($path);
    }

    public function testRechazaArchivosDemasiadoGrandes(): void
    {
        $path = AudioFactory::writeTo($this->tmp->path, 'grande.wav', AudioFactory::wav(20000));

        $this->expectException(HttpException::class);
        $this->expectExceptionCode(413);

        $this->validator(['max_upload_bytes' => 2048])->validateFile($path);
    }

    public function testVerificaElHashDeclarado(): void
    {
        $contenido = AudioFactory::wav();
        $path = AudioFactory::writeTo($this->tmp->path, 'a.wav', $contenido);
        $esperado = hash('sha256', $contenido);

        self::assertSame($esperado, $this->validator()->verifyChecksum($path, $esperado));
        self::assertSame($esperado, $this->validator()->verifyChecksum($path, strtoupper($esperado)));
        self::assertSame($esperado, $this->validator()->verifyChecksum($path, ''), 'Sin hash declarado se calcula el propio.');
    }

    public function testRechazaUnHashQueNoCoincide(): void
    {
        $path = AudioFactory::writeTo($this->tmp->path, 'a.wav', AudioFactory::wav());

        $this->expectException(HttpException::class);
        $this->expectExceptionCode(422);

        $this->validator()->verifyChecksum($path, str_repeat('0', 64));
    }

    public function testRechazaUnHashMalFormado(): void
    {
        $path = AudioFactory::writeTo($this->tmp->path, 'a.wav', AudioFactory::wav());

        $this->expectException(HttpException::class);
        $this->expectExceptionCode(400);

        $this->validator()->verifyChecksum($path, 'no-es-un-hash');
    }

    public function testNormalizaLosMetadatos(): void
    {
        $metadata = $this->validator()->normalizeMetadata([
            'sitio' => '  Reserva Yanacocha  ',
            'estacion' => 'E-07',
            'duracion_segundos' => '58.5',
            'frecuencia_muestreo' => '48000',
            'latitud' => '-0.1236',
            'longitud' => '-78.5896',
            'campo_ignorado' => 'x',
        ]);

        self::assertSame('Reserva Yanacocha', $metadata['sitio']);
        self::assertSame(58.5, $metadata['duracion_segundos']);
        self::assertSame(48000, $metadata['frecuencia_muestreo']);
        self::assertSame(-0.1236, $metadata['latitud']);
        self::assertArrayNotHasKey('campo_ignorado', $metadata);
    }

    public function testRechazaMetadatosNoNumericos(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionCode(400);

        $this->validator()->normalizeMetadata(['duracion_segundos' => 'un minuto']);
    }

    public function testRechazaCoordenadasFueraDeRango(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionMessageMatches('/fuera de rango/');

        $this->validator()->normalizeMetadata(['latitud' => '95.0']);
    }

    public function testNormalizaLaFechaDeGrabacionAUtc(): void
    {
        $ahora = 1758500000;

        self::assertSame('2025-09-22T00:00:00Z', $this->validator()->normalizeRecordedAt('2025-09-21T19:00:00-05:00', $ahora));
        self::assertNull($this->validator()->normalizeRecordedAt(null, $ahora));
        self::assertNull($this->validator()->normalizeRecordedAt('   ', $ahora));
    }

    public function testRechazaUnaFechaDeGrabacionInvalida(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionMessageMatches('/ISO 8601/');

        $this->validator()->normalizeRecordedAt('ayer por la tarde', 1758500000);
    }

    public function testRechazaUnaFechaDeGrabacionEnElFuturo(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionMessageMatches('/futuro/');

        $this->validator()->normalizeRecordedAt('2030-01-01T00:00:00Z', 1758500000);
    }

    public function testToleraUnaDesviacionPequenaDelRelojDelDispositivo(): void
    {
        $ahora = 1758500000;
        $fecha = gmdate('Y-m-d\TH:i:s\Z', $ahora + 60);

        self::assertSame($fecha, $this->validator()->normalizeRecordedAt($fecha, $ahora));
    }

    /** @param array<string,mixed> $overrides */
    private function validator(array $overrides = []): AudioValidator
    {
        $config = new Config(array_merge([
            'max_upload_bytes' => 67108864,
            'min_upload_bytes' => 1024,
            'allowed_formats' => ['wav', 'flac', 'ogg', 'opus', 'mp3'],
            'recorded_at_future_tolerance_seconds' => 300,
        ], $overrides));

        return new AudioValidator($config, new AudioSniffer());
    }
}
