<?php

declare(strict_types=1);

namespace App\Tests\Driver\MySql;

use App\Driver\MySql\MySqlIntrospector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VitessInternalDatabaseTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function names(): iterable
    {
        yield 'base de service de Vitess' => ['_vt', true];
        yield 'base physique d\'un keyspace non partagé' => ['vt_shop_0', true];
        yield 'base physique d\'un shard' => ['vt_shop_-80', true];
        yield 'base physique, casse différente' => ['VT_shop_80-', true];
        yield 'un keyspace' => ['shop', false];
        yield 'un keyspace qui commence par vt_' => ['vt_orders', false];
        yield 'un keyspace dont le nom ressemble à un shard sans en être un' => ['vt_orders_archive', false];
        yield 'une base système' => ['information_schema', false];
        yield 'vide' => ['', false];
    }

    #[DataProvider('names')]
    public function testOnlyTheInternalNamesOfATabletAreRecognised(string $name, bool $internal): void
    {
        self::assertSame($internal, MySqlIntrospector::isVitessInternal($name));
    }
}
