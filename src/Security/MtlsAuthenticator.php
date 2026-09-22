<?php

declare(strict_types=1);

namespace Jocotoco\Security;

use Jocotoco\Config\Config;
use Jocotoco\Exception\HttpException;
use Jocotoco\Http\Request;
use Jocotoco\Support\Clock;

/**
 * Autenticacion por mTLS.
 *
 * nginx ya valida la cadena contra la raiz de step-ca (ssl_verify_client on).
 * Esta clase repite las comprobaciones que dependen de la aplicacion:
 *
 *  1. que nginx efectivamente haya verificado el certificado (SSL_CLIENT_VERIFY),
 *  2. que el emisor sea el esperado (evita confusiones si se agregan CAs),
 *  3. que el certificado este vigente segun el reloj del API,
 *  4. que la identidad del dispositivo este en la lista blanca (si se configuro).
 */
final class MtlsAuthenticator
{
    public function __construct(
        private readonly Config $config,
        private readonly Clock $clock,
    ) {
    }

    public function authenticate(Request $request): DeviceIdentity
    {
        if (!$this->config->bool('require_mtls')) {
            return $this->developmentIdentity($request);
        }

        $verify = strtoupper($request->server('SSL_CLIENT_VERIFY', 'NONE'));
        if ($verify !== 'SUCCESS') {
            throw HttpException::unauthorized(sprintf(
                'Se requiere un certificado de cliente valido emitido por step-ca (SSL_CLIENT_VERIFY=%s).',
                $verify,
            ));
        }

        $certificate = ClientCertificate::fromPem($request->server('SSL_CLIENT_CERT'));
        $now = $this->clock->now();

        // Se tolera una desviacion pequena del reloj hacia atras: un
        // certificado recien emitido por step-ca puede tener notBefore unos
        // segundos por delante del reloj del servidor.
        $skew = $this->config->int('certificate_clock_skew_seconds');
        if ($certificate->isNotYetValid($now + $skew)) {
            throw HttpException::forbidden('El certificado de cliente aun no es valido (revise el reloj del dispositivo).');
        }

        if ($certificate->isExpired($now)) {
            throw HttpException::forbidden('El certificado de cliente expiro; ejecute "step ca renew" en el dispositivo.');
        }

        $expectedIssuer = $this->config->string('ca_issuer_common_name');
        if ($expectedIssuer !== '*' && !$this->issuerMatches($certificate, $expectedIssuer)) {
            throw HttpException::forbidden(sprintf(
                'El certificado no fue emitido por la CA esperada (%s).',
                $expectedIssuer,
            ));
        }

        $identity = $certificate->identity();
        if ($identity === '') {
            throw HttpException::forbidden('El certificado no declara un nombre de dispositivo (SAN DNS o CN).');
        }

        $allowlist = $this->config->list('allowed_devices');
        if ($allowlist !== [] && !$this->identityAllowed($identity, $allowlist)) {
            throw HttpException::forbidden(sprintf('El dispositivo "%s" no esta autorizado en este API.', $identity));
        }

        return new DeviceIdentity($identity, $certificate);
    }

    /**
     * Coincidencia exacta o por sufijo de dominio (".sensores.jocotoco.local").
     *
     * @param list<string> $allowlist
     */
    private function identityAllowed(string $identity, array $allowlist): bool
    {
        $identity = strtolower($identity);

        foreach ($allowlist as $allowed) {
            $allowed = strtolower(trim($allowed));
            if ($allowed === '') {
                continue;
            }

            if ($allowed === $identity) {
                return true;
            }

            if (str_starts_with($allowed, '*.') && str_ends_with($identity, substr($allowed, 1))) {
                return true;
            }
        }

        return false;
    }

    private function issuerMatches(ClientCertificate $certificate, string $expectedIssuer): bool
    {
        foreach (explode(',', $certificate->issuerDn) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            if (strtoupper(trim($key)) === 'CN' && trim($value) === $expectedIssuer) {
                return true;
            }
        }

        return false;
    }

    /**
     * Solo para desarrollo local (require_mtls=false): la identidad se toma de
     * una cabecera. Nunca debe habilitarse en produccion.
     */
    private function developmentIdentity(Request $request): DeviceIdentity
    {
        $identity = $request->header('x-dispositivo-dev', 'dispositivo-dev');
        $now = $this->clock->now();

        return new DeviceIdentity(
            $identity,
            new ClientCertificate(
                subjectDn: 'CN=' . $identity,
                issuerDn: 'CN=desarrollo-sin-mtls',
                commonName: $identity,
                serialNumber: '0',
                sha256Fingerprint: hash('sha256', 'desarrollo:' . $identity),
                validFrom: $now,
                validTo: $now + 3600,
                dnsNames: [$identity],
            ),
        );
    }
}
