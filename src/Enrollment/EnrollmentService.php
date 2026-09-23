<?php

declare(strict_types=1);

namespace Jocotoco\Enrollment;

use Jocotoco\Config\Config;
use Jocotoco\Exception\HttpException;
use Jocotoco\Support\Clock;
use Jocotoco\Support\Logger;

/**
 * Alta automatica de dispositivos.
 *
 * Un dispositivo recien instalado no tiene certificado, de modo que no puede
 * autenticarse por mTLS: presenta un secreto de fabrica y, si todo cuadra,
 * recibe un token de un solo uso con el que pide su certificado a step-ca.
 *
 * El secreto compartido es el punto debil de este esquema (esta en la imagen
 * de todos los equipos), asi que la inscripcion se acota por varios lados:
 *
 *  - viene deshabilitada; hay que activarla a proposito,
 *  - el nombre del dispositivo debe cumplir un patron y, si se configura una
 *    lista, estar en ella,
 *  - cada dispositivo tiene un tope de tokens POR VENTANA DE TIEMPO (5 por
 *    hora): frena el abuso sin dejar fuera a un equipo cuyo primer intento
 *    fallo despues de recibir el token (el token se gasta aunque el
 *    "step ca certificate" posterior no funcione),
 *  - un dispositivo puede bloquearse sin tocar la CA,
 *  - se limita el numero de intentos fallidos por IP,
 *  - todo intento, aceptado o no, queda registrado con IP y motivo.
 */
final class EnrollmentService
{
    public function __construct(
        private readonly Config $config,
        private readonly EnrollmentRepository $repository,
        private readonly TokenIssuer $issuer,
        private readonly Clock $clock,
        private readonly Logger $logger,
    ) {
    }

    /**
     * @return array<string,mixed> cuerpo de la respuesta
     */
    public function enroll(?string $deviceId, ?string $secret, string $ip): array
    {
        $ahora = $this->clock->nowIso8601();

        $rechazar = function (int $status, string $motivo, string $resultado, string $publico) use ($deviceId, $ip, $ahora): never {
            $this->repository->logAttempt($deviceId, $ip, $resultado, $motivo, $ahora);
            $this->logger->warning('Inscripcion rechazada', [
                'dispositivo' => $deviceId,
                'ip' => $ip,
                'motivo' => $motivo,
            ]);

            throw new HttpException(
                $status,
                $publico,
                'Inscripcion rechazada',
                'https://jocotoco.api/problems/inscripcion-rechazada',
            );
        };

        if (!$this->config->bool('enrollment_enabled')) {
            $rechazar(404, 'la inscripcion automatica esta deshabilitada', 'deshabilitada',
                'La inscripcion automatica no esta habilitada en este API.');
        }

        $esperado = (string) $this->config->get('enrollment_secret');
        if (strlen($esperado) < 16) {
            $rechazar(503, 'el secreto de fabrica configurado es demasiado corto', 'mal-configurado',
                'La inscripcion automatica no esta configurada correctamente.');
        }

        // Limite por IP antes de comparar el secreto, para no premiar el
        // sondeo a ciegas.
        $ventana = $this->config->int('enrollment_failure_window_seconds');
        $maxFallos = $this->config->int('enrollment_max_failures_per_ip');
        $desde = gmdate('Y-m-d\TH:i:s\Z', $this->clock->now() - $ventana);

        if ($this->repository->failedAttemptsFrom($ip, $desde) >= $maxFallos) {
            $rechazar(429, sprintf('demasiados intentos fallidos desde %s', $ip), 'limite-ip',
                'Demasiados intentos fallidos; espere antes de reintentar.');
        }

        if (!is_string($secret) || !hash_equals($esperado, $secret)) {
            $rechazar(403, 'secreto de fabrica incorrecto', 'secreto-invalido',
                'El secreto de fabrica no es valido.');
        }

        if (!is_string($deviceId) || preg_match('/^[a-z0-9]([a-z0-9.-]{0,62}[a-z0-9])?$/', $deviceId) !== 1) {
            $rechazar(400, sprintf('nombre de dispositivo invalido: %s', (string) $deviceId), 'nombre-invalido',
                'El identificador del dispositivo debe ser un hostname en minusculas.');
        }

        $permitidos = $this->config->list('enrollment_devices');
        if ($permitidos !== [] && !in_array($deviceId, $permitidos, true)) {
            $rechazar(403, 'el dispositivo no esta en la lista de inscripcion', 'fuera-de-lista',
                'Este dispositivo no esta autorizado a inscribirse.');
        }

        $estado = $this->repository->find($deviceId);

        if ($estado !== null && (int) $estado['blocked'] === 1) {
            $rechazar(403, 'dispositivo bloqueado', 'bloqueado',
                'Este dispositivo esta bloqueado para inscripcion.');
        }

        $tope = $this->config->int('enrollment_max_tokens');
        $ventanaTokens = $this->config->int('enrollment_token_window_seconds');
        $desdeTokens = gmdate('Y-m-d\TH:i:s\Z', $this->clock->now() - $ventanaTokens);
        $emitidosVentana = $this->repository->issuedSince($deviceId, $desdeTokens);

        if ($emitidosVentana >= $tope) {
            $rechazar(
                429,
                sprintf('tope de tokens por ventana alcanzado (%d en %d s)', $emitidosVentana, $ventanaTokens),
                'tope-alcanzado',
                sprintf(
                    'Este dispositivo ya pidio %d tokens en los ultimos %d minutos; espere o pida uno manualmente.',
                    $emitidosVentana,
                    intdiv($ventanaTokens, 60),
                ),
            );
        }

        $emitidos = (int) ($estado['tokens_issued'] ?? 0);

        try {
            $token = $this->issuer->issue($deviceId);
        } catch (TokenIssuerException $exception) {
            $this->repository->logAttempt($deviceId, $ip, 'error-ca', $exception->getMessage(), $ahora);
            $this->logger->error('La CA no emitio el token de inscripcion', [
                'dispositivo' => $deviceId,
                'ip' => $ip,
                'error' => $exception->getMessage(),
            ]);

            throw HttpException::serverError('No se pudo emitir el token de inscripcion.', $exception);
        }

        $this->repository->registerIssued($deviceId, $ip, $ahora);
        $this->repository->logAttempt($deviceId, $ip, 'emitido', sprintf('token %d de %d', $emitidos + 1, $tope), $ahora);
        $this->logger->info('Token de inscripcion emitido', [
            'dispositivo' => $deviceId,
            'ip' => $ip,
            'token_numero' => $emitidos + 1,
        ]);

        // "token" es el campo que consume el cliente; el resto le ahorra
        // tener que llevar la URL y la huella de la CA en su configuracion.
        return [
            'token' => $token,
            'device_id' => $deviceId,
            'expira_en' => $this->config->string('enrollment_token_duration'),
            'ca_url' => $this->config->get('ca_url') ?: null,
            'huella_raiz' => $this->config->get('ca_root_fingerprint') ?: null,
            'tokens_restantes' => $tope - ($emitidosVentana + 1),
            'tokens_emitidos_en_total' => $emitidos + 1,
        ];
    }
}
