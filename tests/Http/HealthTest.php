<?php

declare(strict_types=1);

namespace App\Tests\Http;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HealthTest extends WebTestCase
{
    public function testHealthEndpointReportsOk(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/health');

        self::assertResponseIsSuccessful();
        self::assertJsonStringEqualsJsonString(
            '{"status":"ok","app":"TerrilDB"}',
            (string) $client->getResponse()->getContent(),
        );
    }
}
