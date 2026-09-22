<?php

declare(strict_types=1);

namespace Jocotoco\Security;

/**
 * Dispositivo autenticado (una Raspberry Pi) derivado del certificado mTLS.
 */
final class DeviceIdentity
{
    public function __construct(
        public readonly string $id,
        public readonly ClientCertificate $certificate,
    ) {
    }

    public function certificateFingerprint(): string
    {
        return $this->certificate->sha256Fingerprint;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'certificado' => $this->certificate->toArray(),
        ];
    }
}
