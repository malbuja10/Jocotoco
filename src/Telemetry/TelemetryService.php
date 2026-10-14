<?php

declare(strict_types=1);

namespace Jocotoco\Telemetry;

use Jocotoco\Config\Config;
use Jocotoco\Exception\HttpException;
use Jocotoco\Security\DeviceIdentity;
use Jocotoco\Support\Clock;
use Jocotoco\Support\Logger;

/**
 * Voltaje y estado del dispositivo (seccion 4 del contrato del monitor).
 *
 * Un lote con un registro invalido se rechaza entero. Es deliberado: el
 * monitor borra sus datos locales solo cuando recibe 200/201, asi que
 * aceptar a medias y responder 200 le haria perder los registros que el
 * servidor descarto.
 */
final class TelemetryService
{
    /** Rangos de cordura: descartan datos imposibles sin estrechar la medicion real. */
    private const RANGOS = [
        'cpu_temp' => [-50.0, 150.0],        // grados Celsius
        'cpu_usage_pct' => [0.0, 100.0],
        'ram_usage_pct' => [0.0, 100.0],
        'disk_usage_pct' => [0.0, 100.0],
        'disk_free_gb' => [0.0, 1_000_000.0],
        'uptime_hours' => [0.0, 1_000_000.0],
    ];

    public function __construct(
        private readonly Config $config,
        private readonly TelemetryRepository $repository,
        private readonly Clock $clock,
        private readonly Logger $logger,
    ) {
    }

    /**
     * @param array<string,mixed> $cuerpo
     * @return array<string,mixed>
     */
    public function voltage(array $cuerpo, DeviceIdentity $device): array
    {
        $registros = $this->extraerLista($cuerpo, 'voltage');
        $lecturas = [];

        foreach ($registros as $indice => $registro) {
            $this->comprobarDispositivo($registro, $device, $indice);

            $lecturas[] = [
                'measured_at' => $this->marcaDeTiempo($registro, $indice),
                'value' => $this->requerido($registro, 'value', $indice, -1000.0, 1000.0),
            ];
        }

        $resultado = $this->repository->saveVoltage(
            $device->id,
            $lecturas,
            $this->clock->nowIso8601(),
        );

        $this->logger->info('Voltaje recibido', [
            'dispositivo' => $device->id,
            'recibidas' => count($lecturas),
            'guardadas' => $resultado['guardadas'],
            'duplicadas' => $resultado['duplicadas'],
        ]);

        return [
            'status' => 'ok',
            'recibidas' => count($lecturas),
            'guardadas' => $resultado['guardadas'],
            'duplicadas' => $resultado['duplicadas'],
        ];
    }

    /**
     * @param array<string,mixed> $cuerpo
     * @return array<string,mixed>
     */
    public function status(array $cuerpo, DeviceIdentity $device): array
    {
        $registros = $this->extraerLista($cuerpo, 'device_telemetry');
        $filas = [];

        foreach ($registros as $indice => $registro) {
            $this->comprobarDispositivo($registro, $device, $indice);

            $fila = ['measured_at' => $this->marcaDeTiempo($registro, $indice)];
            foreach (array_keys(self::RANGOS) as $campo) {
                [$minimo, $maximo] = self::RANGOS[$campo];
                $fila[$campo] = $this->opcional($registro, $campo, $indice, $minimo, $maximo);
            }

            $filas[] = $fila;
        }

        $resultado = $this->repository->saveStatus(
            $device->id,
            $filas,
            $this->clock->nowIso8601(),
        );

        $this->logger->info('Telemetria recibida', [
            'dispositivo' => $device->id,
            'recibidas' => count($filas),
            'guardadas' => $resultado['guardadas'],
            'duplicadas' => $resultado['duplicadas'],
        ]);

        return [
            'status' => 'ok',
            'recibidas' => count($filas),
            'guardadas' => $resultado['guardadas'],
            'duplicadas' => $resultado['duplicadas'],
        ];
    }

    /**
     * @param array<string,mixed> $cuerpo
     * @return list<array<string,mixed>>
     */
    private function extraerLista(array $cuerpo, string $clave): array
    {
        if (!array_key_exists($clave, $cuerpo)) {
            throw HttpException::badRequest(sprintf('Falta la clave "%s" con la lista de registros.', $clave));
        }

        $lista = $cuerpo[$clave];
        if (!is_array($lista) || ($lista !== [] && array_is_list($lista) === false)) {
            throw HttpException::badRequest(sprintf('La clave "%s" debe contener una lista.', $clave));
        }

        if ($lista === []) {
            throw HttpException::badRequest(sprintf('La lista "%s" esta vacia.', $clave));
        }

        $maximo = $this->config->int('telemetry_max_records');
        if (count($lista) > $maximo) {
            throw HttpException::payloadTooLarge(
                sprintf('El envio trae %d registros y el maximo por solicitud es %d.', count($lista), $maximo),
                ['recibidos' => count($lista), 'maximo' => $maximo],
            );
        }

        foreach ($lista as $indice => $registro) {
            if (!is_array($registro)) {
                throw HttpException::badRequest(sprintf('El registro %d no es un objeto.', $indice));
            }
        }

        /** @var list<array<string,mixed>> $lista */
        return $lista;
    }

    /** @param array<string,mixed> $registro */
    private function comprobarDispositivo(array $registro, DeviceIdentity $device, int $indice): void
    {
        $declarado = $registro['device_id'] ?? null;
        if (!is_scalar($declarado) || trim((string) $declarado) === '') {
            return;
        }

        if (trim((string) $declarado) !== $device->id) {
            throw HttpException::forbidden(sprintf(
                'El registro %d declara el dispositivo "%s", que no corresponde al certificado (%s).',
                $indice,
                trim((string) $declarado),
                $device->id,
            ));
        }
    }

    /** @param array<string,mixed> $registro */
    private function marcaDeTiempo(array $registro, int $indice): int
    {
        $valor = $registro['timestamp'] ?? null;
        if (!is_numeric($valor)) {
            throw HttpException::badRequest(sprintf('El registro %d no trae un "timestamp" numerico.', $indice));
        }

        $marca = (int) $valor;
        if ($marca > 100_000_000_000) {
            $marca = intdiv($marca, 1000);
        }

        $tolerancia = $this->config->int('recorded_at_future_tolerance_seconds');
        if ($marca < 946_684_800 || $marca > $this->clock->now() + $tolerancia) {
            throw HttpException::badRequest(
                sprintf('El "timestamp" del registro %d no es una fecha plausible (%d).', $indice, $marca),
            );
        }

        return $marca;
    }

    /** @param array<string,mixed> $registro */
    private function requerido(array $registro, string $campo, int $indice, float $minimo, float $maximo): float
    {
        $valor = $registro[$campo] ?? null;
        if (!is_numeric($valor)) {
            throw HttpException::badRequest(
                sprintf('El registro %d no trae un "%s" numerico.', $indice, $campo),
            );
        }

        return $this->enRango((float) $valor, $campo, $indice, $minimo, $maximo);
    }

    /** @param array<string,mixed> $registro */
    private function opcional(array $registro, string $campo, int $indice, float $minimo, float $maximo): ?float
    {
        $valor = $registro[$campo] ?? null;
        if ($valor === null || $valor === '') {
            return null;
        }

        if (!is_numeric($valor)) {
            throw HttpException::badRequest(
                sprintf('El campo "%s" del registro %d debe ser numerico.', $campo, $indice),
            );
        }

        return $this->enRango((float) $valor, $campo, $indice, $minimo, $maximo);
    }

    private function enRango(float $valor, string $campo, int $indice, float $minimo, float $maximo): float
    {
        if ($valor < $minimo || $valor > $maximo) {
            throw HttpException::unprocessable(
                sprintf('El campo "%s" del registro %d esta fuera de rango (%s).', $campo, $indice, $valor),
                ['minimo' => $minimo, 'maximo' => $maximo],
            );
        }

        return $valor;
    }
}
