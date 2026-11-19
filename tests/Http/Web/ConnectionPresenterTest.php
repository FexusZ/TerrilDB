<?php

declare(strict_types=1);

namespace App\Tests\Http\Web;

use App\Core\Connection\ConnectionParameters;
use App\Http\Web\ConnectionPresenter;
use PHPUnit\Framework\TestCase;

final class ConnectionPresenterTest extends TestCase
{
    public function testTheReplicaChoiceIsShownOnlyWhenItIsOn(): void
    {
        $off = ConnectionPresenter::parameters(new ConnectionParameters('db.internal', 3306, 'shop', 'reader', 'secret'));
        $on = ConnectionPresenter::parameters(new ConnectionParameters('db.internal', 3306, 'shop', 'reader', 'secret', readReplica: true));

        self::assertArrayNotHasKey('readReplica', $off);
        self::assertTrue($on['readReplica'] ?? null);
        self::assertStringNotContainsString('secret', json_encode($on, \JSON_THROW_ON_ERROR));
    }
}
