<?php

declare(strict_types=1);

namespace Jocotoco\Support;

/**
 * Identificadores ULID (26 caracteres, base32 de Crockford).
 *
 * Se prefieren sobre UUIDv4 porque son ordenables por tiempo: al listar
 * grabaciones el orden lexicografico coincide con el orden de llegada.
 */
final class Ulid
{
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
    public const LENGTH = 26;

    public static function generate(Clock $clock): string
    {
        return self::encodeTime($clock->nowMilliseconds()) . self::encodeRandom();
    }

    public static function isValid(string $candidate): bool
    {
        return strlen($candidate) === self::LENGTH
            && preg_match('/^[0-9ABCDEFGHJKMNPQRSTVWXYZ]{26}$/', $candidate) === 1;
    }

    private static function encodeTime(int $milliseconds): string
    {
        $encoded = '';
        for ($i = 0; $i < 10; $i++) {
            $encoded = self::ALPHABET[$milliseconds % 32] . $encoded;
            $milliseconds = intdiv($milliseconds, 32);
        }

        return $encoded;
    }

    private static function encodeRandom(): string
    {
        $encoded = '';
        for ($i = 0; $i < 16; $i++) {
            $encoded .= self::ALPHABET[random_int(0, 31)];
        }

        return $encoded;
    }
}
