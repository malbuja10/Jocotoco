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

    public function testConservaElNombreQueEnvioElEquipo(): void
    {
        // El monitor nombra sus archivos AMENAZA_<etiqueta>_<fecha>_<hora>.wav:
        // guardarlo asi deja ver la clase de la deteccion con un "ls".
        $origen = AudioFactory::writeTo($this->tmp->path, 'subida.wav', AudioFactory::wav());

        $relativa = $this->storage->store(
            $origen,
            'rpi-yanacocha-01',
            '01K5TESTULID00000000000010',
            AudioFormat::byName('wav'),
            'AMENAZA_chainsaw_20261109_181442.wav',
        );

        self::assertSame(
            'rpi-yanacocha-01/2025/09/22/AMENAZA_chainsaw_20261109_181442-01K5TESTULID00000000000010.wav',
            $relativa,
        );
        self::assertTrue($this->storage->exists($relativa));
    }

    public function testElUlidDistingueDosEnviosConElMismoNombre(): void
    {
        // El analizador reutiliza nombres (solo conserva los 10 ultimos
        // archivos) y dos equipos pueden coincidir: sin el ULID detras, el
        // segundo envio sobreescribiria al primero.
        $nombre = 'AMENAZA_gunshot_20261109_181442.wav';

        $primera = $this->storage->store(
            AudioFactory::writeTo($this->tmp->path, 'a.wav', AudioFactory::wav(4000)),
            'rpi-uno',
            '01K5TESTULID00000000000011',
            AudioFormat::byName('wav'),
            $nombre,
        );
        $segunda = $this->storage->store(
            AudioFactory::writeTo($this->tmp->path, 'b.wav', AudioFactory::wav(8000)),
            'rpi-uno',
            '01K5TESTULID00000000000012',
            AudioFormat::byName('wav'),
            $nombre,
        );

        self::assertNotSame($primera, $segunda);
        self::assertTrue($this->storage->exists($primera));
        self::assertTrue($this->storage->exists($segunda));
    }

    public function testUnNombreMaliciosoNoEscapaDelDirectorio(): void
    {
        $origen = AudioFactory::writeTo($this->tmp->path, 'subida.wav', AudioFactory::wav());

        $relativa = $this->storage->store(
            $origen,
            'rpi-uno',
            '01K5TESTULID00000000000013',
            AudioFormat::byName('wav'),
            '../../../etc/passwd',
        );

        self::assertSame('rpi-uno/2025/09/22/passwd-01K5TESTULID00000000000013.wav', $relativa);
        self::assertStringStartsWith(
            realpath($this->tmp->child('audio')) . DIRECTORY_SEPARATOR,
            $this->storage->absolutePath($relativa),
        );
    }

    public function testSaneaLosNombresQueNoSirvenComoArchivo(): void
    {
        self::assertSame('AMENAZA_chainsaw_20261109', AudioStorage::sanitizeFilename('AMENAZA_chainsaw_20261109.wav'));
        self::assertSame('passwd', AudioStorage::sanitizeFilename('/etc/passwd'));
        self::assertSame('evidencia', AudioStorage::sanitizeFilename('C:\\audio\\evidencia.wav'));
        // Solo ASCII en el disco: los acentos y los espacios pasan a "_".
        self::assertSame('con_espacios_y_acent_s', AudioStorage::sanitizeFilename('con espacios y acentós.wav'));
        self::assertSame('a.b', AudioStorage::sanitizeFilename('a..b.wav'), 'Ningun nombre puede contener "..".');
        self::assertSame('', AudioStorage::sanitizeFilename('.wav'));
        self::assertSame('', AudioStorage::sanitizeFilename('../..'));
        self::assertSame('', AudioStorage::sanitizeFilename(''));
        self::assertSame(64, mb_strlen(AudioStorage::sanitizeFilename(str_repeat('x', 200) . '.wav')));
    }

    public function testLaExtensionSaleDelFormatoRealNoDelNombre(): void
    {
        // Un nombre ".wav" sobre un archivo FLAC no debe quedar mintiendo.
        $origen = AudioFactory::writeTo($this->tmp->path, 'subida.flac', AudioFactory::flac());

        $relativa = $this->storage->store(
            $origen,
            'rpi-uno',
            '01K5TESTULID00000000000014',
            AudioFormat::byName('flac'),
            'AMENAZA_chainsaw_20261109_181442.wav',
        );

        self::assertStringEndsWith('-01K5TESTULID00000000000014.flac', $relativa);
    }

    public function testConLaOpcionApagadaGuardaSoloElUlid(): void
    {
        $storage = new AudioStorage(
            $this->tmp->child('audio'),
            new FrozenClock(1758500000),
            keepOriginalFilename: false,
        );
        $origen = AudioFactory::writeTo($this->tmp->path, 'subida.wav', AudioFactory::wav());

        $relativa = $storage->store(
            $origen,
            'rpi-uno',
            '01K5TESTULID00000000000015',
            AudioFormat::byName('wav'),
            'AMENAZA_chainsaw_20261109_181442.wav',
        );

        self::assertSame('rpi-uno/2025/09/22/01K5TESTULID00000000000015.wav', $relativa);
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
