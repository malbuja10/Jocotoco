<?php

declare(strict_types=1);

namespace Jocotoco\Tests\Unit;

use Jocotoco\Audio\AudioFormat;
use Jocotoco\Exception\StorageException;
use Jocotoco\Storage\AudioStorage;
use Jocotoco\Support\FrozenClock;
use Jocotoco\Tests\Support\AudioFactory;
use Jocotoco\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;

final class AudioStorageTest extends TestCase
{
    private TemporaryDirectory $tmp;
    private AudioStorage $storage;

    protected function setUp(): void
    {
        $this->tmp = new TemporaryDirectory();
        $this->storage = new AudioStorage($this->tmp->child('audio'), new FrozenClock(1758500000));
        $this->storage->ensureDirectory($this->tmp->child('audio'));
    }

    protected function tearDown(): void
    {
        $this->tmp->remove();
    }

    public function testGuardaElAudioEnUnaRutaPorDispositivoYFecha(): void
    {
        $origen = AudioFactory::writeTo($this->tmp->path, 'origen.wav', AudioFactory::wav());

        $relativa = $this->storage->store($origen, 'rpi-yanacocha-01', '01K5TESTULID00000000000000', AudioFormat::byName('wav'));

        self::assertSame('rpi-yanacocha-01/2025/09/22/01K5TESTULID00000000000000.wav', $relativa);
        self::assertTrue($this->storage->exists($relativa));
        self::assertFileDoesNotExist($origen, 'El archivo temporal debe moverse, no copiarse.');
    }

    public function testNoDejaArchivosParcialesVisibles(): void
    {
        $origen = AudioFactory::writeTo($this->tmp->path, 'origen.wav', AudioFactory::wav());
        $relativa = $this->storage->store($origen, 'rpi-uno', '01K5TESTULID00000000000001', AudioFormat::byName('wav'));

        $directorio = dirname($this->storage->absolutePath($relativa));
        $parciales = glob($directorio . '/*.parcial') ?: [];

        self::assertSame([], $parciales);
    }

    public function testSaneaElNombreDelDispositivoTomadoDelCertificado(): void
    {
        self::assertSame('rpi-uno', AudioStorage::sanitizeSegment('rpi-uno'));
        self::assertSame('rpi.sensores.local', AudioStorage::sanitizeSegment('rpi.sensores.local'));
        self::assertSame('etc_passwd', AudioStorage::sanitizeSegment('../../etc/passwd'));
        self::assertSame('desconocido', AudioStorage::sanitizeSegment(''));
        self::assertSame('desconocido', AudioStorage::sanitizeSegment('../'));
        self::assertSame('a_b', AudioStorage::sanitizeSegment('a/b'));
    }

    public function testUnDispositivoConNombreMaliciosoNoEscapaDelArbol(): void
    {
        $origen = AudioFactory::writeTo($this->tmp->path, 'origen.wav', AudioFactory::wav());
        $relativa = $this->storage->store($origen, '../../etc', '01K5TESTULID00000000000002', AudioFormat::byName('wav'));

        self::assertStringStartsWith('etc/', $relativa);
        self::assertStringStartsWith(
            realpath($this->tmp->child('audio')) . DIRECTORY_SEPARATOR,
            $this->storage->absolutePath($relativa),
        );
    }

    public function testRechazaRutasQueSalenDelDirectorioDeAudio(): void
    {
        $this->expectException(StorageException::class);

        $this->storage->absolutePath('../../etc/passwd');
    }

    public function testEliminaUnaGrabacion(): void
    {
        $origen = AudioFactory::writeTo($this->tmp->path, 'origen.flac', AudioFactory::flac());
        $relativa = $this->storage->store($origen, 'rpi-uno', '01K5TESTULID00000000000003', AudioFormat::byName('flac'));

        self::assertTrue($this->storage->delete($relativa));
        self::assertFalse($this->storage->exists($relativa));
        self::assertFalse($this->storage->delete($relativa));
    }

    public function testInformaElEspacioLibre(): void
    {
        self::assertGreaterThan(0, $this->storage->freeBytes());
    }
}
