<?php

declare(strict_types=1);

namespace Jocotoco\Tests\Unit;

use Jocotoco\Audio\WavInfo;
use Jocotoco\Audio\WavInspector;
use Jocotoco\Tests\Support\AudioFactory;
use Jocotoco\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;

/**
 * El contrato del monitor declara frecuencia, canales, profundidad y
 * duracion en el JSON del evento, pero el servidor se queda con lo que dice
 * la cabecera del WAV: aqui se comprueba que esa lectura es correcta.
 */
final class WavInspectorTest extends TestCase
{
    private TemporaryDirectory $tmp;
    private WavInspector $inspector;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmp = new TemporaryDirectory();
        $this->inspector = new WavInspector();
    }

    protected function tearDown(): void
    {
        $this->tmp->remove();

        parent::tearDown();
    }

    public function testLeeUnWavMono16Bit(): void
    {
        $info = $this->inspeccionar(AudioFactory::wavPersonalizado(16000, 1, 16, 1.0));

        self::assertNotNull($info);
        self::assertSame(16000, $info->frecuenciaMuestreo);
        self::assertSame(1, $info->canales);
        self::assertSame(16, $info->bitsPorMuestra);
        self::assertSame(1.0, $info->duracionSegundos);
        self::assertSame(32000, $info->bytesDeDatos);
        self::assertTrue($info->esPcm());
    }

    public function testLeeUnWavEstereoDe24BitsA44100(): void
    {
        $info = $this->inspeccionar(AudioFactory::wavPersonalizado(44100, 2, 24, 0.5));

        self::assertNotNull($info);
        self::assertSame(44100, $info->frecuenciaMuestreo);
        self::assertSame(2, $info->canales);
        self::assertSame(24, $info->bitsPorMuestra);
        self::assertSame(0.5, $info->duracionSegundos);
    }

    public function testCalculaLaDuracionConFraccionesDeSegundo(): void
    {
        // 4000 muestras a 16 kHz: la grabacion tipica del monitor es corta.
        $info = $this->inspeccionar(AudioFactory::wav(4000, 16000));

        self::assertNotNull($info);
        self::assertSame(0.25, $info->duracionSegundos);
    }

    public function testSaltaLosBloquesIntermediosAntesDeData(): void
    {
        // Un WAV real suele traer LIST o fact entre "fmt " y "data".
        $data = str_repeat("\x00\x01", 8000);
        $lista = 'INFOISFT' . pack('V', 8) . "editor\x00\x00";
        $wav = 'RIFF' . pack('V', 0) . 'WAVE'
            . 'fmt ' . pack('V', 16) . pack('vvVVvv', 1, 1, 8000, 16000, 2, 16)
            . 'LIST' . pack('V', strlen($lista)) . $lista
            . 'fact' . pack('V', 4) . pack('V', 8000)
            . 'data' . pack('V', strlen($data)) . $data;

        $info = $this->inspeccionar($wav);

        self::assertNotNull($info);
        self::assertSame(8000, $info->frecuenciaMuestreo);
        self::assertSame(1.0, $info->duracionSegundos);
    }

    public function testRespetaElRellenoDeLosBloquesDeTamanoImpar(): void
    {
        // Los bloques se alinean a bytes pares: sin el relleno, "data" se lee
        // desplazado un byte y el archivo parece corrupto.
        $data = str_repeat("\x00\x01", 4000);
        $nota = 'impar';   // 5 bytes: obliga a un byte de relleno
        $wav = 'RIFF' . pack('V', 0) . 'WAVE'
            . 'note' . pack('V', strlen($nota)) . $nota . "\x00"
            . 'fmt ' . pack('V', 16) . pack('vvVVvv', 1, 1, 16000, 32000, 2, 16)
            . 'data' . pack('V', strlen($data)) . $data;

        $info = $this->inspeccionar($wav);

        self::assertNotNull($info);
        self::assertSame(16000, $info->frecuenciaMuestreo);
        self::assertSame(0.25, $info->duracionSegundos);
    }

    public function testCalculaLaDuracionAunqueLaCabeceraNoTraigaBytesPorSegundo(): void
    {
        $data = str_repeat("\x00\x01", 16000);
        $wav = 'RIFF' . pack('V', 0) . 'WAVE'
            . 'fmt ' . pack('V', 16) . pack('vvVVvv', 1, 1, 16000, 0, 2, 16)
            . 'data' . pack('V', strlen($data)) . $data;

        $info = $this->inspeccionar($wav);

        self::assertNotNull($info);
        self::assertSame(1.0, $info->duracionSegundos);
    }

    public function testReconoceLosFormatosQueNoSonPcm(): void
    {
        $float = $this->inspeccionar(AudioFactory::wavPersonalizado(16000, 1, 32, 0.1, WavInfo::IEEE_FLOAT));
        $adpcm = $this->inspeccionar(AudioFactory::wavPersonalizado(16000, 1, 16, 0.1, 2));

        self::assertNotNull($float);
        self::assertNotNull($adpcm);
        self::assertTrue($float->esPcm(), 'IEEE float es audio sin comprimir y se acepta.');
        self::assertFalse($adpcm->esPcm(), 'ADPCM esta comprimido y el contrato pide WAV PCM.');
    }

    public function testDevuelveNuloSiElArchivoNoEsUnWav(): void
    {
        self::assertNull($this->inspeccionar(AudioFactory::flac()));
        self::assertNull($this->inspeccionar(AudioFactory::notAudio()));
        self::assertNull($this->inspeccionar('RIFF'));
        self::assertNull($this->inspeccionar(''));
    }

    public function testDevuelveNuloSiFaltaElBloqueDeFormato(): void
    {
        $wav = 'RIFF' . pack('V', 0) . 'WAVE' . 'data' . pack('V', 4) . "\x00\x00\x00\x00";

        self::assertNull($this->inspeccionar($wav));
    }

    public function testDevuelveNuloSiElArchivoNoExiste(): void
    {
        self::assertNull($this->inspector->inspect($this->tmp->child('no-existe.wav')));
    }

    private function inspeccionar(string $contenido): ?\Jocotoco\Audio\WavInfo
    {
        $ruta = AudioFactory::writeTo($this->tmp->path, 'wav-' . bin2hex(random_bytes(4)) . '.wav', $contenido);

        return $this->inspector->inspect($ruta);
    }
}
