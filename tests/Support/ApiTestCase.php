<?php

declare(strict_types=1);

namespace Jocotoco\Tests\Support;

use Jocotoco\Application;
use Jocotoco\Http\Request;
use Jocotoco\Http\Response;
use Jocotoco\Support\FrozenClock;
use PHPUnit\Framework\TestCase;

/**
 * Base para pruebas que levantan la aplicacion completa contra
 * directorios temporales y una base SQLite propia.
 */
abstract class ApiTestCase extends TestCase
{
    protected TemporaryDirectory $tmp;
    protected CertificateFactory $ca;
    protected FrozenClock $clock;
    protected Application $app;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmp = new TemporaryDirectory();
        $this->ca = new CertificateFactory($this->tmp->child('pki'));
        $this->clock = new FrozenClock(time());
        $this->app = $this->createApplication();
        $this->app->prepare();
    }

    protected function tearDown(): void
    {
        $this->tmp->remove();

        parent::tearDown();
    }

    /** @param array<string,mixed> $overrides */
    protected function createApplication(array $overrides = []): Application
    {
        return Application::create(array_merge([
            'data_dir' => $this->tmp->path,
            'audio_dir' => $this->tmp->child('audio'),
            'db_path' => $this->tmp->child('jocotoco.sqlite'),
            'tmp_dir' => $this->tmp->child('tmp'),
            'log_path' => $this->tmp->child('api.log'),
            'require_mtls' => true,
            'ca_issuer_common_name' => 'Jocotoco Intermediate CA',
            'allowed_devices' => [],
            'reader_devices' => [],
            'certificate_clock_skew_seconds' => 60,
        ], $overrides), $this->clock);
    }

    /**
     * Construye una peticion autenticada con un certificado de cliente,
     * imitando los parametros SSL_CLIENT_* que envia nginx.
     *
     * @param array<string,string> $headers
     * @param array<string,string> $query
     */
    protected function request(
        string $method,
        string $path,
        ?string $clientPem = null,
        array $headers = [],
        ?string $bodyFile = null,
        array $query = [],
        string $verify = 'SUCCESS',
    ): Request {
        $server = [
            'REQUEST_METHOD' => $method,
            'REQUEST_URI' => $path . ($query === [] ? '' : '?' . http_build_query($query)),
            'QUERY_STRING' => http_build_query($query),
            'SERVER_PROTOCOL' => 'HTTP/1.1',
        ];

        if ($clientPem !== null) {
            $server['SSL_CLIENT_VERIFY'] = $verify;
            $server['SSL_CLIENT_CERT'] = CertificateFactory::escape($clientPem);
            $server['SSL_CLIENT_S_DN'] = 'CN=cliente';
            $server['SSL_PROTOCOL'] = 'TLSv1.3';
        }

        foreach ($headers as $name => $value) {
            $key = strtoupper(str_replace('-', '_', $name));
            $server[in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true) ? $key : 'HTTP_' . $key] = $value;
        }

        $body = null;
        if ($bodyFile !== null) {
            $handle = fopen($bodyFile, 'rb');
            $body = $handle === false ? null : $handle;
            $server['CONTENT_LENGTH'] = (string) filesize($bodyFile);
        }

        /** @var array<string,string> $query */
        return new Request(
            $method,
            rtrim($path, '/') === '' ? '/' : rtrim($path, '/'),
            $this->headersFromServer($server),
            $query,
            $server,
            [],
            $body,
        );
    }

    /** @return array<string,mixed> */
    protected function decode(Response $response): array
    {
        $decoded = json_decode($response->body, true);
        self::assertIsArray($decoded, 'La respuesta no es JSON valido: ' . $response->body);

        return $decoded;
    }

    /**
     * @param array<string,mixed> $server
     * @return array<string,string>
     */
    private function headersFromServer(array $server): array
    {
        $headers = [];
        foreach ($server as $key => $value) {
            if (!is_string($key) || !is_scalar($value)) {
                continue;
            }

            if (str_starts_with($key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = (string) $value;
            } elseif ($key === 'CONTENT_TYPE' || $key === 'CONTENT_LENGTH') {
                $headers[strtolower(str_replace('_', '-', $key))] = (string) $value;
            }
        }

        return $headers;
    }
}
