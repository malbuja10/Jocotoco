<?php

declare(strict_types=1);

namespace Jocotoco\Enrollment;

/**
 * Pide el token a step-ca ejecutando un envoltorio con privilegios.
 *
 * El API no tiene (ni debe tener) la contrasena del provisioner de la CA:
 * invoca /usr/local/sbin/jocotoco-emitir-token a traves de sudo, con una
 * regla que solo permite ese comando. El envoltorio valida el nombre del
 * dispositivo, ejecuta "step ca token" como el usuario de la CA y devuelve
 * el token por la salida estandar.
 *
 * Se usa proc_open con la forma de arreglo: no hay shell involucrado, asi
 * que el nombre del dispositivo no puede convertirse en inyeccion de
 * comandos.
 */
final class StepCaTokenIssuer implements TokenIssuer
{
    /** @param list<string> $comando */
    public function __construct(
        private readonly array $comando,
        private readonly string $duracion = '60m',
        private readonly int $tiempoLimite = 20,
    ) {
    }

    public static function fromCommandLine(string $comando, string $duracion = '60m'): self
    {
        $partes = preg_split('/\s+/', trim($comando)) ?: [];

        return new self(array_values(array_filter($partes)), $duracion);
    }

    public function issue(string $deviceId): string
    {
        if ($this->comando === []) {
            throw new TokenIssuerException('No hay comando configurado para emitir tokens.');
        }

        $argv = [...$this->comando, $deviceId, $this->duracion];

        $descriptores = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $proceso = @proc_open($argv, $descriptores, $tuberias);
        if (!is_resource($proceso)) {
            throw new TokenIssuerException(sprintf(
                'No se pudo ejecutar %s (revise disable_functions de php-fpm).',
                $this->comando[0],
            ));
        }

        fclose($tuberias[0]);
        stream_set_blocking($tuberias[1], false);
        stream_set_blocking($tuberias[2], false);

        $salida = '';
        $error = '';
        $limite = microtime(true) + $this->tiempoLimite;

        while (true) {
            $salida .= (string) stream_get_contents($tuberias[1]);
            $error .= (string) stream_get_contents($tuberias[2]);

            $estado = proc_get_status($proceso);
            if (!$estado['running']) {
                break;
            }

            if (microtime(true) > $limite) {
                proc_terminate($proceso, SIGKILL);
                fclose($tuberias[1]);
                fclose($tuberias[2]);
                proc_close($proceso);

                throw new TokenIssuerException(sprintf(
                    'La emision del token excedio %d segundos.',
                    $this->tiempoLimite,
                ));
            }

            usleep(50_000);
        }

        $salida .= (string) stream_get_contents($tuberias[1]);
        $error .= (string) stream_get_contents($tuberias[2]);
        fclose($tuberias[1]);
        fclose($tuberias[2]);
        $codigo = proc_close($proceso);

        $token = trim($salida);

        if ($codigo !== 0 || $token === '') {
            throw new TokenIssuerException(sprintf(
                'step-ca no emitio el token (codigo %d): %s',
                $codigo,
                trim($error) !== '' ? trim($error) : 'sin detalle',
            ));
        }

        // Un JWT tiene tres partes separadas por punto; si lo que volvio no
        // lo parece, es un mensaje de error y no debe entregarse al cliente.
        if (substr_count($token, '.') !== 2) {
            throw new TokenIssuerException('La salida del emisor no es un token valido.');
        }

        return $token;
    }
}
