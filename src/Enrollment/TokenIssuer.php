<?php

declare(strict_types=1);

namespace Jocotoco\Enrollment;

/**
 * Emite tokens de un solo uso de step-ca. Se abstrae para poder probar el
 * caso de uso sin una CA real.
 */
interface TokenIssuer
{
    /**
     * @throws TokenIssuerException si la CA no pudo emitir el token
     */
    public function issue(string $deviceId): string;
}
