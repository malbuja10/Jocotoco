<?php

declare(strict_types=1);

namespace Jocotoco\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Los rechazos que hace Apache antes de llegar a PHP (sin certificado de
 * cliente, o cuerpo mayor que LimitRequestBody) se sirven con estos
 * archivos via ErrorDocument. Deben mantener el mismo formato problem+json
 * que las respuestas de la aplicacion.
 */
final class ErrorDocumentsTest extends TestCase
{
    private const DIRECTORY = __DIR__ . '/../../public/errores';

    /** @return iterable<string,array{int}> */
    public static function estadosProvider(): iterable
    {
        yield '403 sin certificado de cliente' => [403];
        yield '413 cuerpo demasiado grande' => [413];
    }

    #[DataProvider('estadosProvider')]
    public function testElDocumentoDeErrorEsProblemJsonValido(int $status): void
    {
        $path = sprintf('%s/%d.json', self::DIRECTORY, $status);
        self::assertFileExists($path, 'Falta el documento de error que referencia ErrorDocument en Apache.');

        $decoded = json_decode((string) file_get_contents($path), true);

        self::assertIsArray($decoded, 'El documento de error debe ser JSON valido.');
        self::assertSame($status, $decoded['status'] ?? null, 'El campo "status" debe coincidir con el codigo HTTP.');
        self::assertArrayHasKey('type', $decoded);
        self::assertArrayHasKey('title', $decoded);
        self::assertNotEmpty($decoded['detail'] ?? '', 'El documento debe explicar el rechazo en "detail".');
    }

    public function testElVirtualHostReferenciaLosDocumentosExistentes(): void
    {
        $config = (string) file_get_contents(__DIR__ . '/../../deploy/apache/jocotoco-api.conf');

        $declarados = preg_match_all('/^\s*ErrorDocument\s+(\d{3})\s+(\S+)/m', $config, $matches);

        self::assertGreaterThan(0, $declarados, 'El VirtualHost debe declarar ErrorDocument para los rechazos de Apache.');

        foreach ($matches[2] as $index => $ruta) {
            $archivo = self::DIRECTORY . '/' . basename($ruta);
            self::assertFileExists($archivo, sprintf(
                'ErrorDocument %s apunta a %s, que no existe en public/errores.',
                $matches[1][$index],
                $ruta,
            ));
        }
    }
}
