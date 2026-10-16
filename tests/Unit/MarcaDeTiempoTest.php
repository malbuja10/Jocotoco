<?php

declare(strict_types=1);

namespace Jocotoco\Tests\Unit;

use Jocotoco\Support\MarcaDeTiempo;
use PHPUnit\Framework\TestCase;

/**
 * El contrato promete epoch en segundos, pero el monitor envia tambien
 * cadenas ISO 8601 sin zona horaria (datetime.now().isoformat()).
 */
final class MarcaDeTiempoTest extends TestCase
{
    public function testAceptaEpochEnSegundos(): void
    {
        self::assertSame(1792182425, MarcaDeTiempo::interpretar(1792182425));
        self::assertSame(1792182425, MarcaDeTiempo::interpretar('1792182425'));
        self::assertSame(1792182425, MarcaDeTiempo::interpretar(1792182425.9));
    }

    public function testConvierteLosMilisegundos(): void
    {
        self::assertSame(1792182425, MarcaDeTiempo::interpretar(1792182425000));
    }

    public function testInterpretaIso8601ConDesfase(): void
    {
        self::assertSame(1792182425, MarcaDeTiempo::interpretar('2026-10-16T20:27:05Z'));
        self::assertSame(1792182425, MarcaDeTiempo::interpretar('2026-10-16T20:27:05+00:00'));
        self::assertSame(1792182425, MarcaDeTiempo::interpretar('2026-10-16T15:27:05-05:00'));
        self::assertSame(1792182425, MarcaDeTiempo::interpretar('2026-10-16T20:27:05.767217Z'));
    }

    public function testUnaFechaSinZonaSeLeeEnLaDelDispositivo(): void
    {
        $cadena = '2026-10-16T15:27:05.767217';

        self::assertSame(1792182425, MarcaDeTiempo::interpretar($cadena, 'America/Guayaquil'));
        self::assertSame(1792164425, MarcaDeTiempo::interpretar($cadena, 'UTC'));
    }

    public function testDevuelveNuloConLoQueNoEsUnaFecha(): void
    {
        foreach (['ayer', '', '2026-13-45T99:99:99', 'martes', null, true, []] as $valor) {
            self::assertNull(MarcaDeTiempo::interpretar($valor), var_export($valor, true));
        }
    }

    public function testReconoceLasFechasImplausibles(): void
    {
        $ahora = 1792182425;

        self::assertTrue(MarcaDeTiempo::esPlausible($ahora - 86400, $ahora, 300));
        self::assertTrue(MarcaDeTiempo::esPlausible($ahora + 120, $ahora, 300));
        self::assertFalse(MarcaDeTiempo::esPlausible(1, $ahora, 300), 'anterior al ano 2000');
        self::assertFalse(MarcaDeTiempo::esPlausible($ahora + 86400, $ahora, 300), 'en el futuro');
    }
}
