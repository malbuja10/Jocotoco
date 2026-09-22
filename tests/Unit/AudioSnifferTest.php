<?php

declare(strict_types=1);

namespace Jocotoco\Tests\Unit;

use Jocotoco\Audio\AudioSniffer;
use Jocotoco\Tests\Support\AudioFactory;
use Jocotoco\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;

final class AudioSnifferTest extends TestCase
{
    private AudioSniffer $sniffer;
    private TemporaryDirectory $tmp;

    protected function setUp(): void
    {
        $this->sniffer = new AudioSniffer();
        $this->tmp = new TemporaryDirectory();
    }

    protected function tearDown(): void
    {
        $this->tmp->remove();
    }

    public function testDetectaWav(): void
    {
        self::assertSame('wav', $this->sniffer->sniff(AudioFactory::wav())?->name);
    }

    public function testDetectaFlac(): void
    {
        self::assertSame('flac', $this->sniffer->sniff(AudioFactory::flac())?->name);
    }

    public function testDetectaOpusDentroDeOgg(): void
    {
        self::assertSame('opus', $this->sniffer->sniff(AudioFactory::oggOpus())?->name);
    }

    public function testDetectaOggSinOpus(): void
    {
        $vorbis = 'OggS' . str_repeat("\x00", 22) . "\x01vorbis" . str_repeat("\x00", 64);
        self::assertSame('ogg', $this->sniffer->sniff($vorbis)?->name);
    }

    public function testDetectaMp3ConEtiquetaId3(): void
    {
        self::assertSame('mp3', $this->sniffer->sniff(AudioFactory::mp3())?->name);
    }

    public function testDetectaMp3PorSincronizacionDeTrama(): void
    {
        $frame = "\xFF\xFB\x90\x00" . str_repeat("\x00", 64);
        self::assertSame('mp3', $this->sniffer->sniff($frame)?->name);
    }

    public function testDetectaAacAdts(): void
    {
        $adts = "\xFF\xF1\x50\x80" . str_repeat("\x00", 64);
        self::assertSame('aac', $this->sniffer->sniff($adts)?->name);
    }

    public function testDetectaM4a(): void
    {
        $m4a = "\x00\x00\x00\x20" . 'ftypM4A ' . str_repeat("\x00", 64);
        self::assertSame('m4a', $this->sniffer->sniff($m4a)?->name);
    }

    public function testRechazaContenidoQueNoEsAudio(): void
    {
        self::assertNull($this->sniffer->sniff(AudioFactory::notAudio()));
    }

    public function testRechazaCabeceraDemasiadoCorta(): void
    {
        self::assertNull($this->sniffer->sniff('RIFF'));
    }

    public function testRechazaWavConCabeceraIncompleta(): void
    {
        // RIFF sin el marcador WAVE (podria ser AVI u otro contenedor RIFF).
        self::assertNull($this->sniffer->sniff('RIFF' . pack('V', 100) . 'AVI ' . str_repeat("\x00", 32)));
    }

    public function testLeeElFormatoDesdeUnArchivo(): void
    {
        $path = AudioFactory::writeTo($this->tmp->path, 'grabacion.wav', AudioFactory::wav());
        self::assertSame('wav', $this->sniffer->sniffFile($path)?->name);
    }

    public function testDevuelveNuloSiElArchivoNoExiste(): void
    {
        self::assertNull($this->sniffer->sniffFile($this->tmp->child('inexistente.wav')));
    }
}
