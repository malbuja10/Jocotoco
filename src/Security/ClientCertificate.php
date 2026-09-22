<?php

declare(strict_types=1);

namespace Jocotoco\Security;

use Jocotoco\Exception\HttpException;

/**
 * Certificado de cliente X.509 emitido por step-ca, tal como lo entrega
 * nginx a php-fpm mediante los parametros SSL_CLIENT_*.
 */
final class ClientCertificate
{
    /**
     * @param list<string> $dnsNames
     * @param list<string> $uriNames
     * @param list<string> $emailNames
     */
    public function __construct(
        public readonly string $subjectDn,
        public readonly string $issuerDn,
        public readonly string $commonName,
        public readonly string $serialNumber,
        public readonly string $sha256Fingerprint,
        public readonly int $validFrom,
        public readonly int $validTo,
        public readonly array $dnsNames = [],
        public readonly array $uriNames = [],
        public readonly array $emailNames = [],
        public readonly string $pem = '',
    ) {
    }

    /**
     * Construye el certificado a partir del PEM que nginx expone en
     * $ssl_client_escaped_cert (URL-encoded) o $ssl_client_cert (multilinea).
     */
    public static function fromPem(string $rawPem): self
    {
        $pem = self::decodePem($rawPem);
        if ($pem === '') {
            throw HttpException::unauthorized('No se recibio el certificado de cliente (SSL_CLIENT_CERT vacio).');
        }

        $parsed = @openssl_x509_parse($pem);
        if (!is_array($parsed)) {
            throw HttpException::unauthorized('El certificado de cliente no se pudo interpretar.');
        }

        $subject = is_array($parsed['subject'] ?? null) ? $parsed['subject'] : [];
        $issuer = is_array($parsed['issuer'] ?? null) ? $parsed['issuer'] : [];

        $fingerprint = @openssl_x509_fingerprint($pem, 'sha256');

        $sans = self::parseSubjectAltName((string) ($parsed['extensions']['subjectAltName'] ?? ''));

        return new self(
            subjectDn: self::formatDn($subject),
            issuerDn: self::formatDn($issuer),
            commonName: self::flatten($subject['CN'] ?? ''),
            serialNumber: (string) ($parsed['serialNumberHex'] ?? $parsed['serialNumber'] ?? ''),
            sha256Fingerprint: is_string($fingerprint) ? strtolower($fingerprint) : '',
            validFrom: (int) ($parsed['validFrom_time_t'] ?? 0),
            validTo: (int) ($parsed['validTo_time_t'] ?? 0),
            dnsNames: $sans['DNS'],
            uriNames: $sans['URI'],
            emailNames: $sans['email'],
            pem: $pem,
        );
    }

    /**
     * Identidad del dispositivo: primer SAN DNS y, si no existe, el CN.
     * step-ca coloca el nombre solicitado en ambos campos.
     */
    public function identity(): string
    {
        return $this->dnsNames[0] ?? $this->commonName;
    }

    public function isExpired(int $now): bool
    {
        return $this->validTo > 0 && $now > $this->validTo;
    }

    public function isNotYetValid(int $now): bool
    {
        return $this->validFrom > 0 && $now < $this->validFrom;
    }

    public function secondsUntilExpiry(int $now): int
    {
        return max(0, $this->validTo - $now);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'identidad' => $this->identity(),
            'common_name' => $this->commonName,
            'subject_dn' => $this->subjectDn,
            'issuer_dn' => $this->issuerDn,
            'numero_serie' => $this->serialNumber,
            'huella_sha256' => $this->sha256Fingerprint,
            'valido_desde' => gmdate(DATE_ATOM, $this->validFrom),
            'valido_hasta' => gmdate(DATE_ATOM, $this->validTo),
            'sans_dns' => $this->dnsNames,
            'sans_uri' => $this->uriNames,
        ];
    }

    private static function decodePem(string $rawPem): string
    {
        $pem = trim($rawPem);
        if ($pem === '' || $pem === '-') {
            return '';
        }

        // $ssl_client_escaped_cert llega URL-encoded en una sola linea.
        if (!str_contains($pem, "\n") && str_contains($pem, '%')) {
            $pem = rawurldecode($pem);
        }

        // $ssl_client_cert llega con cada linea prefijada por un tabulador.
        $pem = str_replace("\t", '', $pem);

        return str_contains($pem, 'BEGIN CERTIFICATE') ? trim($pem) : '';
    }

    /**
     * @return array{DNS:list<string>,URI:list<string>,email:list<string>}
     */
    private static function parseSubjectAltName(string $subjectAltName): array
    {
        $result = ['DNS' => [], 'URI' => [], 'email' => []];
        if ($subjectAltName === '') {
            return $result;
        }

        foreach (explode(',', $subjectAltName) as $entry) {
            $entry = trim($entry);
            $separator = strpos($entry, ':');
            if ($separator === false) {
                continue;
            }

            $kind = trim(substr($entry, 0, $separator));
            $value = trim(substr($entry, $separator + 1));
            if ($value === '') {
                continue;
            }

            if (array_key_exists($kind, $result)) {
                $result[$kind][] = $value;
            }
        }

        return $result;
    }

    /** @param array<string,mixed> $dn */
    private static function formatDn(array $dn): string
    {
        $parts = [];
        foreach ($dn as $key => $value) {
            $parts[] = $key . '=' . self::flatten($value);
        }

        return implode(',', $parts);
    }

    private static function flatten(mixed $value): string
    {
        if (is_array($value)) {
            return implode('+', array_map(strval(...), $value));
        }

        return is_scalar($value) ? (string) $value : '';
    }
}
