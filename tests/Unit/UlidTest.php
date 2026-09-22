<?php

declare(strict_types=1);

namespace Jocotoco\Tests\Unit;

use Jocotoco\Support\FrozenClock;
use Jocotoco\Support\SystemClock;
use Jocotoco\Support\Ulid;
use PHPUnit\Framework\TestCase;

final class UlidTest extends TestCase
{
    public function testGeneraIdentificadoresDe26Caracteres(): void
    {
        $id = Ulid::generate(new SystemClock());

        self::assertSame(26, strlen($id));
        self::assertTrue(Ulid::isValid($id));
    }

    public function testLosIdentificadoresSonOrdenablesPorTiempo(): void
    {
        $clock = new FrozenClock(1700000000);
        $primero = Ulid::generate($clock);
        $clock->advance(1);
        $segundo = Ulid::generate($clock);

        self::assertLessThan(0, strcmp($primero, $segundo));
    }

    public function testNoRepiteIdentificadoresEnElMismoMilisegundo(): void
    {
        $clock = new FrozenClock(1700000000);
        $ids = [];
        for ($i = 0; $i < 200; $i++) {
            $ids[] = Ulid::generate($clock);
        }

        self::assertCount(200, array_unique($ids));
    }

    public function testRechazaIdentificadoresInvalidos(): void
    {
        self::assertFalse(Ulid::isValid(''));
        self::assertFalse(Ulid::isValid('corto'));
        self::assertFalse(Ulid::isValid(str_repeat('U', 26)), 'U no pertenece al alfabeto Crockford');
        self::assertFalse(Ulid::isValid('../../etc/passwd'));
    }
}
