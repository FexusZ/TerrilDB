<?php

declare(strict_types=1);

namespace App\Tests\Integration\Support;

use App\Tests\Integration\Driver\TargetDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Les objets programmables jetables : un déclencheur qui recopie vers une table de synchronisation et une fonction déterministe
 * dont les valeurs débordent les bornes d'un entier 64 bits. Rien ne reste après `drop()`, déclencheur et fonction compris.
 */
#[Group('integration')]
final class ProgrammableObjectsTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function targets(): iterable
    {
        return TargetDatabase::relational();
    }

    #[DataProvider('targets')]
    public function testTheTriggerCopiesEachInsertIntoTheSyncTable(string $env): void
    {
        $objects = ProgrammableObjects::create($env);
        try {
            $objects->exec(\sprintf('INSERT INTO %s (id, qty) VALUES (1, 10), (2, 20), (3, 30)', $objects->source));

            self::assertSame('3', $objects->scalar(\sprintf('SELECT COUNT(*) FROM %s', $objects->sync)));
            self::assertSame('60', $objects->scalar(\sprintf('SELECT SUM(qty) FROM %s', $objects->sync)));
            self::assertSame('3', $objects->scalar(\sprintf("SELECT COUNT(*) FROM %s WHERE op = 'insert'", $objects->sync)));
            self::assertSame(1, $objects->triggerCount(), 'The introspection sees the trigger of the source table.');
        } finally {
            $objects->drop();
        }

        self::assertSame([], SkewedTable::leftovers($env));
    }

    #[DataProvider('targets')]
    public function testTheFunctionReturnsValuesBeyondTheBoundsOf64BitIntegers(string $env): void
    {
        $objects = ProgrammableObjects::create($env);
        try {
            $values = array_map(static fn (int $k): string => $objects->scalar(\sprintf('SELECT %s(%d)', $objects->function, $k)), [0, 1, 2, 3]);
            // Calculé deux fois, la fonction rend la même chose : elle est déterministe.
            $again = $objects->scalar(\sprintf('SELECT %s(1)', $objects->function));
        } finally {
            $objects->drop();
        }

        self::assertSame([
            '9223372036854775807',
            '9223372036854775808',
            '-9223372036854775809',
            '99999999999999999999999999999999999999',
        ], $values);
        self::assertSame('9223372036854775808', $again);
    }
}
