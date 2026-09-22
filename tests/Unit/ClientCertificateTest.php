<?php

declare(strict_types=1);

namespace Jocotoco\Tests\Unit;

use Jocotoco\Exception\HttpException;
use Jocotoco\Security\ClientCertificate;
use Jocotoco\Tests\Support\CertificateFactory;
use Jocotoco\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;

final class ClientCertificateTest extends TestCase
{
    private TemporaryDirectory $tmp;
    private CertificateFactory $ca;

    protected function setUp(): void
    {
        $this->tmp = new TemporaryDirectory();
        $this->ca = new CertificateFactory($this->tmp->child('pki'));
    }

    protected function tearDown(): void
    {
        $this->tmp->remove();
    }

    public function testInterpretaElCertificadoEscapadoDeNginx(): void
    {
        $pem = $this->ca->clientPem('rpi-yanacocha-01');
        $certificate = ClientCertificate::fromPem(CertificateFactory::escape($pem));

        self::assertSame('rpi-yanacocha-01', $certificate->identity());
        self::assertSame(['rpi-yanacocha-01'], $certificate->dnsNames);
        self::assertStringContainsString('CN=Jocotoco Intermediate CA', $certificate->issuerDn);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $certificate->sha256Fingerprint);
    }

    public function testInterpretaElCertificadoConLineasYTabulaciones(): void
    {
        $pem = $this->ca->clientPem('rpi-mindo-02');
        // nginx entrega $ssl_client_cert con un tabulador al inicio de cada linea.
        $conTabulaciones = implode("\n\t", explode("\n", $pem));

        $certificate = ClientCertificate::fromPem($conTabulaciones);

        self::assertSame('rpi-mindo-02', $certificate->identity());
    }

    public function testUsaElCommonNameCuandoNoHaySan(): void
    {
        $pem = $this->ca->clientPem('', 1, 'panel-interno');
        $certificate = ClientCertificate::fromPem($pem);

        self::assertSame('panel-interno', $certificate->identity());
    }

    public function testDetectaVigencia(): void
    {
        $certificate = ClientCertificate::fromPem($this->ca->clientPem('rpi-uno', 1));
        $ahora = time();

        self::assertFalse($certificate->isExpired($ahora));
        self::assertFalse($certificate->isNotYetValid($ahora));
        self::assertTrue($certificate->isExpired($ahora + 172800));
        self::assertGreaterThan(0, $certificate->secondsUntilExpiry($ahora));
        self::assertSame(0, $certificate->secondsUntilExpiry($ahora + 172800));
    }

    public function testRechazaUnCertificadoVacio(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionCode(401);

        ClientCertificate::fromPem('');
    }

    public function testRechazaUnGuionQueNginxEnviaCuandoNoHayCertificado(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionCode(401);

        ClientCertificate::fromPem('-');
    }

    public function testRechazaUnPemCorrupto(): void
    {
        $this->expectException(HttpException::class);

        ClientCertificate::fromPem("-----BEGIN CERTIFICATE-----\nbasura\n-----END CERTIFICATE-----");
    }

    public function testExponeLosDatosParaAuditoria(): void
    {
        $certificate = ClientCertificate::fromPem($this->ca->clientPem('rpi-cuyabeno-03'));
        $datos = $certificate->toArray();

        self::assertSame('rpi-cuyabeno-03', $datos['identidad']);
        self::assertArrayHasKey('huella_sha256', $datos);
        self::assertArrayHasKey('numero_serie', $datos);
        self::assertArrayHasKey('valido_hasta', $datos);
        self::assertArrayNotHasKey('pem', $datos, 'El PEM completo no debe exponerse en las respuestas.');
    }
}
