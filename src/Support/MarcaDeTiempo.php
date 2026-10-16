<?php

declare(strict_types=1);

namespace Jocotoco\Support;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Interpreta la marca de tiempo que envia un dispositivo.
 *
 * El contrato del monitor dice "number; normalmente epoch en segundos",
 * pero el codigo del equipo guarda el instante con
 * datetime.now().isoformat(): una cadena ISO 8601 sin zona horaria. Si el
 * servidor solo aceptara numeros, un evento detectado hace tres dias y
 * subido hoy quedaria fechado hoy, que es justo lo que no se quiere de un
 * monitor que pasa semanas sin enlace.
 *
 * Por eso se aceptan tres formas:
 *
 *   - epoch en segundos (lo que dice el contrato);
 *   - epoch en milisegundos, el error mas comun;
 *   - ISO 8601; si trae desfase ("+00:00", "Z", "-05:00") manda ese, y si
 *     no lo trae se interpreta en la zona configurada en "device_timezone",
 *     que es la del equipo, no la del servidor.
 */
final class MarcaDeTiempo
{
    /** Antes de esto no hay dispositivo posible: 2000-01-01. */
    private const MINIMO = 946_684_800;

    /** Por encima de esto el valor viene en milisegundos. */
    private const UMBRAL_MILISEGUNDOS = 100_000_000_000;

    /**
     * Devuelve el epoch en segundos, o null si el valor no es una fecha
     * reconocible (quien llama decide si eso es un error o un descarte).
     */
    public static function interpretar(mixed $valor, string $zonaDelDispositivo = 'UTC'): ?int
    {
        if (is_bool($valor) || $valor === null) {
            return null;
        }

        if (is_numeric($valor)) {
            $marca = (int) $valor;

            return $marca > self::UMBRAL_MILISEGUNDOS ? intdiv($marca, 1000) : $marca;
        }

        if (!is_string($valor) || trim($valor) === '') {
            return null;
        }

        return self::desdeCadena(trim($valor), $zonaDelDispositivo);
    }

    /** Una marca plausible: ni anterior al ano 2000 ni en el futuro. */
    public static function esPlausible(int $marca, int $ahora, int $tolerancia): bool
    {
        return $marca >= self::MINIMO && $marca <= $ahora + $tolerancia;
    }

    private static function desdeCadena(string $valor, string $zonaDelDispositivo): ?int
    {
        // Solo ISO 8601: "2026-10-16T20:27:05", con o sin fraccion de
        // segundo y con o sin desfase. No se acepta texto libre, para no
        // adivinar lo que el dispositivo no dijo.
        $patron = '/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:?\d{2})?$/';
        if (preg_match($patron, $valor) !== 1) {
            return null;
        }

        $traeDesfase = preg_match('/(Z|[+-]\d{2}:?\d{2})$/', $valor) === 1;

        try {
            $zona = new DateTimeZone($traeDesfase ? 'UTC' : $zonaDelDispositivo);
            $fecha = new DateTimeImmutable($valor, $zona);
        } catch (Throwable) {
            return null;
        }

        return $fecha->getTimestamp();
    }
}
