<?php

declare(strict_types=1);

namespace App\Tests\Driver;

use App\Core\Connection\ConnectionParameters;
use App\Core\Connection\Engine;
use App\Driver\DbalConnector;
use PHPUnit\Framework\TestCase;

final class DbalConnectorTest extends TestCase
{
    public function testItDoesNotOpenAnEngineThatHasNoDbalDriver(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new DbalConnector())->connect(Engine::ClickHouse, new ConnectionParameters('db.example.test', 8123, 'shop', 'reader', 'not-a-real-secret'));
    }
}
