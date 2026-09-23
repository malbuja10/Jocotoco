<?php

declare(strict_types=1);

namespace Jocotoco;

use Jocotoco\Audio\AudioSniffer;
use Jocotoco\Audio\AudioValidator;
use Jocotoco\Audio\PayloadReader;
use Jocotoco\Audio\RecordingService;
use Jocotoco\Config\Config;
use Jocotoco\Controller\EnrollmentController;
use Jocotoco\Controller\HealthController;
use Jocotoco\Controller\IdentityController;
use Jocotoco\Controller\RecordingsController;
use Jocotoco\Enrollment\EnrollmentRepository;
use Jocotoco\Enrollment\EnrollmentService;
use Jocotoco\Enrollment\StepCaTokenIssuer;
use Jocotoco\Exception\HttpException;
use Jocotoco\Exception\StorageException;
use Jocotoco\Http\Request;
use Jocotoco\Http\Response;
use Jocotoco\Http\Router;
use Jocotoco\Security\MtlsAuthenticator;
use Jocotoco\Storage\AudioStorage;
use Jocotoco\Storage\Database;
use Jocotoco\Storage\RecordingRepository;
use Jocotoco\Support\Clock;
use Jocotoco\Support\Logger;
use Jocotoco\Support\SystemClock;
use Throwable;

/**
 * Composicion de la aplicacion: configuracion, dependencias, rutas y
 * traduccion de excepciones a respuestas HTTP.
 */
final class Application
{
    private readonly Router $router;
    private readonly MtlsAuthenticator $authenticator;
    private readonly Logger $logger;

    private function __construct(
        private readonly Config $config,
        private readonly Database $database,
        private readonly AudioStorage $storage,
        private readonly RecordingRepository $repository,
        private readonly RecordingService $service,
        private readonly AudioValidator $validator,
        private readonly Clock $clock,
        Logger $logger,
    ) {
        $this->logger = $logger;
        $this->authenticator = new MtlsAuthenticator($config, $clock);
        $this->router = $this->routes();
    }

    /**
     * @param array<string,mixed> $overrides valores que ganan a los defaults y al entorno
     */
    public static function create(array $overrides = [], ?Clock $clock = null): self
    {
        $root = dirname(__DIR__);
        $defaults = require $root . '/config/config.php';
        $config = Config::fromEnvironment($defaults)->with($overrides);

        $dataDir = rtrim($config->string('data_dir'), '/');
        $audioDir = $config->get('audio_dir') ?: $dataDir . '/audio';
        $dbPath = $config->get('db_path') ?: $dataDir . '/jocotoco.sqlite';
        $tmpDir = $config->get('tmp_dir') ?: $dataDir . '/tmp';
        $logPath = $config->get('log_path') ?: $dataDir . '/logs/api.log';

        $config = $config->with([
            'audio_dir' => (string) $audioDir,
            'db_path' => (string) $dbPath,
            'tmp_dir' => (string) $tmpDir,
            'log_path' => (string) $logPath,
        ]);

        $clock ??= new SystemClock();
        $logger = new Logger($config->string('log_path'), $clock);

        $database = new Database($config->string('db_path'), $root . '/database/migrations');
        $storage = new AudioStorage($config->string('audio_dir'), $clock);
        $repository = new RecordingRepository($database);
        $validator = new AudioValidator($config, new AudioSniffer());
        $reader = new PayloadReader($config->string('tmp_dir'), $config->int('max_upload_bytes'));
        $service = new RecordingService($reader, $validator, $storage, $repository, $clock, $logger);

        return new self($config, $database, $storage, $repository, $service, $validator, $clock, $logger);
    }

    public function config(): Config
    {
        return $this->config;
    }

    public function database(): Database
    {
        return $this->database;
    }

    public function storage(): AudioStorage
    {
        return $this->storage;
    }

    public function repository(): RecordingRepository
    {
        return $this->repository;
    }

    /**
     * Prepara directorios y esquema. Se ejecuta desde bin/jocotoco migrate,
     * no en cada peticion.
     *
     * @return list<string>
     */
    public function prepare(): array
    {
        $this->storage->ensureDirectory($this->config->string('audio_dir'));
        $this->storage->ensureDirectory($this->config->string('tmp_dir'));

        return $this->database->migrate();
    }

    public function handle(Request $request): Response
    {
        $requestId = $request->header('x-request-id') !== ''
            ? substr($request->header('x-request-id'), 0, 64)
            : bin2hex(random_bytes(8));

        try {
            $response = $this->router->dispatch($request);
        } catch (HttpException $exception) {
            $response = $this->problemFrom($exception, $request, $requestId);
        } catch (StorageException $exception) {
            $this->logger->error('Error de almacenamiento', [
                'request_id' => $requestId,
                'ruta' => $request->path(),
                'error' => $exception->getMessage(),
            ]);
            $response = Response::problem(500, 'Error interno', 'Error de almacenamiento en el servidor.');
        } catch (Throwable $exception) {
            $this->logger->error('Excepcion no controlada', [
                'request_id' => $requestId,
                'ruta' => $request->path(),
                'excepcion' => $exception::class,
                'error' => $exception->getMessage(),
                'archivo' => $exception->getFile() . ':' . $exception->getLine(),
            ]);
            $response = Response::problem(500, 'Error interno', 'Ocurrio un error inesperado al procesar la peticion.');
        }

        return $response->withHeaders([
            'X-Request-Id' => $requestId,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }

    private function problemFrom(HttpException $exception, Request $request, string $requestId): Response
    {
        if ($exception->status() >= 500) {
            $this->logger->error($exception->getMessage(), ['request_id' => $requestId, 'ruta' => $request->path()]);
        } elseif (in_array($exception->status(), [401, 403], true)) {
            $this->logger->warning('Acceso rechazado', [
                'request_id' => $requestId,
                'ruta' => $request->path(),
                'motivo' => $exception->getMessage(),
                'subject_dn' => $request->server('SSL_CLIENT_S_DN'),
                'verify' => $request->server('SSL_CLIENT_VERIFY', 'NONE'),
            ]);
        }

        $extra = $exception->extra();
        $headers = [];
        if (isset($extra['allow']) && is_array($extra['allow'])) {
            $headers['Allow'] = implode(', ', $extra['allow']);
        }

        $response = Response::problem(
            $exception->status(),
            $exception->title(),
            $exception->getMessage(),
            $exception->type(),
            $extra,
        );

        return $headers === [] ? $response : $response->withHeaders($headers);
    }

    private function routes(): Router
    {
        $router = new Router();

        $health = new HealthController(
            $this->database,
            $this->storage,
            $this->repository,
            $this->clock,
            $this->config->string('app_version'),
        );
        $identity = new IdentityController($this->repository, $this->clock);

        $enrollment = new EnrollmentController(new EnrollmentService(
            $this->config,
            new EnrollmentRepository($this->database),
            StepCaTokenIssuer::fromCommandLine(
                (string) $this->config->get('enrollment_token_command'),
                $this->config->string('enrollment_token_duration'),
            ),
            $this->clock,
            $this->logger,
        ));
        $recordings = new RecordingsController($this->service, $this->repository, $this->validator, $this->config);

        // Publica sobre TLS: permite chequeos de salud sin certificado.
        $router->get('/v1/salud', $health);

        // Tambien publica, y por necesidad: el dispositivo que se inscribe
        // todavia no tiene certificado con el que autenticarse.
        $router->post('/v1/inscripcion', $enrollment);

        $router->get('/v1/yo', $this->authenticated($identity));
        $router->get('/v1/limites', $this->authenticated($recordings->limits(...)));
        $router->post('/v1/grabaciones', $this->authenticated($recordings->store(...)));
        $router->get('/v1/grabaciones', $this->authenticated($recordings->index(...)));
        $router->get('/v1/grabaciones/{id}', $this->authenticated($recordings->show(...)));
        $router->get('/v1/grabaciones/{id}/audio', $this->authenticated($recordings->download(...)));
        $router->delete('/v1/grabaciones/{id}', $this->authenticated($recordings->destroy(...)));
        $router->get('/v1/dispositivos', $this->authenticated($recordings->devices(...)));

        return $router;
    }

    /**
     * Envuelve un manejador para exigir mTLS y registrar la actividad del
     * dispositivo antes de ejecutarlo.
     */
    private function authenticated(callable $handler): callable
    {
        return function (Request $request, array $params) use ($handler): Response {
            $device = $this->authenticator->authenticate($request);

            try {
                $this->repository->touchDevice(
                    $device->id,
                    $device->certificateFingerprint(),
                    $this->clock->nowIso8601(),
                );
            } catch (Throwable $exception) {
                $this->logger->warning('No se pudo registrar la actividad del dispositivo', [
                    'dispositivo' => $device->id,
                    'error' => $exception->getMessage(),
                ]);
            }

            return $handler($request, $params, $device);
        };
    }
}
