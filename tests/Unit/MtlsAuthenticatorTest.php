<?php

declare(strict_types=1);

namespace Jocotoco\Tests\Unit;

use Jocotoco\Config\Config;
use Jocotoco\Exception\HttpException;
use Jocotoco\Http\Request;
use Jocotoco\Security\MtlsAuthenticator;
use Jocotoco\Support\FrozenClock;
use Jocotoco\Tests\Support\CertificateFactory;
use Jocotoco\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;

final class MtlsAuthenticatorTest extends TestCase
{
    private TemporaryDirectory $tmp;
    private CertificateFactory $ca;
    private FrozenClock $clock;

    protected function setUp(): void
    {
        $this->tmp = new TemporaryDirectory();
        $this->ca = new CertificateFactory($this->tmp->child('pki'));
        $this->clock = new FrozenClock(time());
    }

    protected function tearDown(): void
    {
        $this->tmp->remove();
    }

    public function testAutenticaUnDispositivoConCertificadoValido(): void
    {
        $device = $this->authenticator()->authenticate($this->requestWith($this->ca->clientPem('rpi-yanacocha-01')));

        self::assertSame('rpi-yanacocha-01', $device->id);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $device->certificateFingerprint());
    }

    public function testRechazaCuandoNginxNoVerificoElCertificado(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionCode(401);

        $this->authenticator()->authenticate(
            $this->requestWith($this->ca->clientPem('rpi-yanacocha-01'), 'FAILED:self signed certificate'),
        );
    }

    public function testRechazaCuandoNoHayCertificado(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionCode(401);

        $this->authenticator()->authenticate(new Request('GET', '/v1/yo'));
    }

    public function testRechazaUnCertificadoDeOtraCa(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionCode(403);

        $this->authenticator()->authenticate($this->requestWith($this->ca->foreignClientPem('rpi-intruso')));
    }

    public function testAceptaCualquierEmisorCuandoSeConfiguraComodin(): void
    {
        $device = $this->authenticator(['ca_issuer_common_name' => '*'])
            ->authenticate($this->requestWith($this->ca->foreignClientPem('rpi-otra-ca')));

        self::assertSame('rpi-otra-ca', $device->id);
    }

    public function testRechazaUnCertificadoExpirado(): void
    {
        $pem = $this->ca->clientPem('rpi-yanacocha-01', 1);
        $this->clock->advance(2 * 86400);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessageMatches('/expiro/');

        $this->authenticator()->authenticate($this->requestWith($pem));
    }

    public function testToleraUnaDesviacionPequenaDelRelojDelServidor(): void
    {
        $pem = $this->ca->clientPem('rpi-yanacocha-01');
        $this->clock->advance(-30);

        self::assertSame('rpi-yanacocha-01', $this->authenticator()->authenticate($this->requestWith($pem))->id);
    }

    public function testRechazaUnCertificadoAunNoVigenteMasAllaDeLaTolerancia(): void
    {
        $pem = $this->ca->clientPem('rpi-yanacocha-01');
        $this->clock->advance(-7200);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessageMatches('/aun no es valido/');

        $this->authenticator()->authenticate($this->requestWith($pem));
    }

    public function testRechazaUnDispositivoFueraDeLaListaBlanca(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionCode(403);

        $this->authenticator(['allowed_devices' => ['rpi-mindo-02']])
            ->authenticate($this->requestWith($this->ca->clientPem('rpi-yanacocha-01')));
    }

    public function testAceptaUnDispositivoDeLaListaBlanca(): void
    {
        $device = $this->authenticator(['allowed_devices' => ['rpi-mindo-02', 'rpi-yanacocha-01']])
            ->authenticate($this->requestWith($this->ca->clientPem('rpi-yanacocha-01')));

        self::assertSame('rpi-yanacocha-01', $device->id);
    }

    public function testAceptaComodinDeDominioEnLaListaBlanca(): void
    {
        $device = $this->authenticator(['allowed_devices' => ['*.sensores.jocotoco.local']])
            ->authenticate($this->requestWith($this->ca->clientPem('rpi-07.sensores.jocotoco.local')));

        self::assertSame('rpi-07.sensores.jocotoco.local', $device->id);
    }

    public function testEnDesarrolloSinMtlsUsaLaCabeceraDeDispositivo(): void
    {
        $authenticator = $this->authenticator(['require_mtls' => false]);
        $request = new Request('GET', '/v1/yo', ['X-Dispositivo-Dev' => 'rpi-laboratorio']);

        self::assertSame('rpi-laboratorio', $authenticator->authenticate($request)->id);
    }

    /** @param array<string,mixed> $overrides */
    private function authenticator(array $overrides = []): MtlsAuthenticator
    {
        $config = new Config(array_merge([
            'require_mtls' => true,
            'ca_issuer_common_name' => 'Jocotoco Intermediate CA',
            'allowed_devices' => [],
            'reader_devices' => [],
            'certificate_clock_skew_seconds' => 60,
        ], $overrides));

        return new MtlsAuthenticator($config, $this->clock);
    }

    private function requestWith(string $pem, string $verify = 'SUCCESS'): Request
    {
        return new Request('GET', '/v1/yo', [], [], [
            'SSL_CLIENT_VERIFY' => $verify,
            'SSL_CLIENT_CERT' => CertificateFactory::escape($pem),
        ]);
    }
}
