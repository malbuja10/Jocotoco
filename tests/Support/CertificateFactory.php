<?php

declare(strict_types=1);

namespace Jocotoco\Tests\Support;

use OpenSSLAsymmetricKey;
use OpenSSLCertificate;
use RuntimeException;

/**
 * Genera una jerarquia de certificados equivalente a la de step-ca
 * (CA intermedia ECDSA P-256 + certificado de cliente con SAN DNS)
 * para probar la autenticacion mTLS sin depender de un step-ca real.
 */
final class CertificateFactory
{
    private OpenSSLCertificate $caCertificate;
    private OpenSSLAsymmetricKey $caKey;

    public function __construct(private readonly string $workingDirectory, private readonly string $caCommonName = 'Jocotoco Intermediate CA')
    {
        if (!is_dir($this->workingDirectory)) {
            mkdir($this->workingDirectory, 0o700, true);
        }

        $this->caKey = $this->newKey();
        $config = $this->writeConfig('v3_ca', []);

        $csr = openssl_csr_new(
            ['commonName' => $this->caCommonName, 'organizationName' => 'Jocotoco'],
            $this->caKey,
            $this->signingOptions($config, 'v3_ca'),
        );

        if ($csr === false) {
            throw new RuntimeException('No se pudo crear la CSR de la CA: ' . openssl_error_string());
        }

        $certificate = openssl_csr_sign($csr, null, $this->caKey, 3650, $this->signingOptions($config, 'v3_ca'), 1);
        if ($certificate === false) {
            throw new RuntimeException('No se pudo firmar la CA: ' . openssl_error_string());
        }

        $this->caCertificate = $certificate;
    }

    public function caPem(): string
    {
        openssl_x509_export($this->caCertificate, $pem);

        return (string) $pem;
    }

    /**
     * Devuelve el PEM de un certificado de cliente para el dispositivo dado.
     */
    public function clientPem(string $deviceName, int $days = 1, ?string $commonName = null): string
    {
        $key = $this->newKey();
        $config = $this->writeConfig('v3_client', [$deviceName]);
        $options = $this->signingOptions($config, 'v3_client');

        $csr = openssl_csr_new(['commonName' => $commonName ?? $deviceName], $key, $options);
        if ($csr === false) {
            throw new RuntimeException('No se pudo crear la CSR del cliente: ' . openssl_error_string());
        }

        $certificate = openssl_csr_sign($csr, $this->caCertificate, $this->caKey, $days, $options, random_int(2, PHP_INT_MAX));
        if ($certificate === false) {
            throw new RuntimeException('No se pudo firmar el certificado de cliente: ' . openssl_error_string());
        }

        openssl_x509_export($certificate, $pem);

        return (string) $pem;
    }

    /** Certificado firmado por otra CA, para probar el rechazo por emisor. */
    public function foreignClientPem(string $deviceName): string
    {
        return (new self($this->workingDirectory . '/ajena', 'Otra CA'))->clientPem($deviceName);
    }

    /** PEM en la variante URL-encoded de una sola linea (nginx). */
    public static function escape(string $pem): string
    {
        return rawurlencode($pem);
    }

    private function newKey(): OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);

        if ($key === false) {
            throw new RuntimeException('No se pudo generar la llave: ' . openssl_error_string());
        }

        return $key;
    }

    /** @param list<string> $dnsNames */
    private function writeConfig(string $section, array $dnsNames): string
    {
        $alt = '';
        foreach (array_values(array_filter($dnsNames)) as $index => $name) {
            $alt .= sprintf("DNS.%d = %s\n", $index + 1, $name);
        }

        $contents = <<<CNF
        [ req ]
        distinguished_name = req_dn
        prompt = no

        [ req_dn ]

        [ v3_ca ]
        basicConstraints = critical, CA:TRUE, pathlen:1
        keyUsage = critical, digitalSignature, cRLSign, keyCertSign

        [ v3_client ]
        basicConstraints = critical, CA:FALSE
        keyUsage = critical, digitalSignature
        extendedKeyUsage = clientAuth
        CNF;

        if ($alt !== '') {
            $contents .= "\nsubjectAltName = @alt_names\n\n[ alt_names ]\n" . $alt;
        }

        $path = $this->workingDirectory . '/openssl-' . $section . '-' . substr(hash('sha256', $contents), 0, 8) . '.cnf';
        file_put_contents($path, $contents . "\n");

        return $path;
    }

    /** @return array<string,mixed> */
    private function signingOptions(string $config, string $section): array
    {
        return [
            'digest_alg' => 'sha256',
            'config' => $config,
            'req_extensions' => $section,
            'x509_extensions' => $section,
            'encrypt_key' => false,
        ];
    }
}
