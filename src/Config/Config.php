<?php

declare(strict_types=1);

namespace Jocotoco\Config;

use Jocotoco\Exception\ConfigurationException;

/**
 * Configuracion de la aplicacion.
 *
 * Los valores se resuelven en este orden: variables de entorno
 * (prefijo JOCOTOCO_) > archivo config/config.php > valores por defecto.
 */
final class Config
{
    public const ENV_PREFIX = 'JOCOTOCO_';

    /** @param array<string,mixed> $values */
    public function __construct(private readonly array $values)
    {
    }

    /**
     * @param array<string,mixed> $defaults
     * @param array<string,string>|null $env
     */
    public static function fromEnvironment(array $defaults, ?array $env = null): self
    {
        // getenv() es la fuente mas fiable: con variables_order sin "E"
        // $_ENV queda vacio, y bajo el servidor embebido de PHP $_SERVER se
        // reemplaza por los datos de la peticion.
        $env ??= array_merge(
            array_filter(getenv(), is_string(...)),
            array_filter($_ENV, is_string(...)),
            array_filter($_SERVER, is_string(...)),
        );
        $values = $defaults;

        foreach ($defaults as $key => $default) {
            $envKey = self::ENV_PREFIX . strtoupper($key);
            if (!array_key_exists($envKey, $env)) {
                continue;
            }

            $raw = (string) $env[$envKey];
            $values[$key] = match (true) {
                is_bool($default) => self::toBool($raw),
                is_int($default) => self::toInt($envKey, $raw),
                is_array($default) => self::toList($raw),
                default => $raw,
            };
        }

        return new self($values);
    }

    public function string(string $key): string
    {
        $value = $this->get($key);
        if (!is_string($value) || $value === '') {
            throw new ConfigurationException(sprintf('La clave de configuracion "%s" debe ser un texto no vacio.', $key));
        }

        return $value;
    }

    public function int(string $key): int
    {
        $value = $this->get($key);
        if (!is_int($value)) {
            throw new ConfigurationException(sprintf('La clave de configuracion "%s" debe ser un entero.', $key));
        }

        return $value;
    }

    public function bool(string $key): bool
    {
        $value = $this->get($key);
        if (!is_bool($value)) {
            throw new ConfigurationException(sprintf('La clave de configuracion "%s" debe ser un booleano.', $key));
        }

        return $value;
    }

    /** @return list<string> */
    public function list(string $key): array
    {
        $value = $this->get($key);
        if (!is_array($value)) {
            throw new ConfigurationException(sprintf('La clave de configuracion "%s" debe ser una lista.', $key));
        }

        return array_values(array_map(strval(...), $value));
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    public function get(string $key): mixed
    {
        if (!array_key_exists($key, $this->values)) {
            throw new ConfigurationException(sprintf('Clave de configuracion desconocida: "%s".', $key));
        }

        return $this->values[$key];
    }

    /** @param array<string,mixed> $overrides */
    public function with(array $overrides): self
    {
        return new self(array_merge($this->values, $overrides));
    }

    /** @return array<string,mixed> */
    public function all(): array
    {
        return $this->values;
    }

    private static function toBool(string $raw): bool
    {
        return in_array(strtolower(trim($raw)), ['1', 'true', 'yes', 'on', 'si'], true);
    }

    private static function toInt(string $envKey, string $raw): int
    {
        $trimmed = trim($raw);
        if (!preg_match('/^-?\d+$/', $trimmed)) {
            throw new ConfigurationException(sprintf('La variable de entorno %s debe ser numerica, se recibio "%s".', $envKey, $raw));
        }

        return (int) $trimmed;
    }

    /** @return list<string> */
    private static function toList(string $raw): array
    {
        $parts = array_map(trim(...), explode(',', $raw));

        return array_values(array_filter($parts, static fn (string $part): bool => $part !== ''));
    }
}
