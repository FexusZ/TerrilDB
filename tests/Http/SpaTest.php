<?php

declare(strict_types=1);

namespace App\Tests\Http;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SpaTest extends WebTestCase
{
    public function testTheRootLeadsToTheApplication(): void
    {
        $client = static::createClient();

        $client->request('GET', '/');

        self::assertResponseRedirects('/app/', 302);
    }

    public function testAnyApplicationAddressIsAnsweredWithProtectiveHeaders(): void
    {
        $client = static::createClient();

        $client->request('GET', '/app/connections/abc/tables/orders');

        // 200 si le front est compilé, 503 avec une explication sinon : dans les deux cas la page est protégée.
        self::assertContains($client->getResponse()->getStatusCode(), [200, 503]);
        self::assertStringContainsString("script-src 'self'", (string) $client->getResponse()->headers->get('Content-Security-Policy'));
        self::assertSame('nosniff', $client->getResponse()->headers->get('X-Content-Type-Options'));
    }

    public function testTheApplicationDoesNotAcceptWrites(): void
    {
        $client = static::createClient();

        $client->request('POST', '/app/x');

        self::assertResponseStatusCodeSame(405);
    }
}
