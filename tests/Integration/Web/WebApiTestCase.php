<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use App\Entity\User;
use App\Security\UserRegistrar;
use Doctrine\DBAL\Exception\ConnectionException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Tests fonctionnels de l'API web : le conteneur est redémarré à chaque requête (comme en production) et les
 * écritures sont annulées à la fin du test (DAMA).
 */
abstract class WebApiTestCase extends WebTestCase
{
    protected const PASSWORD = 'correct horse battery';

    protected KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $limiterCache = self::getContainer()->get('cache.rate_limiter');
        self::assertInstanceOf(CacheItemPoolInterface::class, $limiterCache);
        $limiterCache->clear();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        try {
            $entityManager->getConnection()->executeQuery('SELECT 1');
        } catch (ConnectionException) {
            self::markTestSkipped('The internal database is unreachable (docker compose up -d --wait db).');
        }
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    protected function call(string $method, string $uri, array $body = [], bool $csrf = true): array
    {
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        if ($csrf) {
            $server['HTTP_X_TERRILDB_REQUEST'] = '1';
        }
        $this->client->request($method, $uri, server: $server, content: [] === $body ? '' : json_encode($body, \JSON_THROW_ON_ERROR));

        $content = (string) $this->client->getResponse()->getContent();
        if ('' === $content) {
            return [];
        }
        $decoded = json_decode($content, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        $typed = [];
        foreach ($decoded as $key => $value) {
            $typed[(string) $key] = $value;
        }

        return $typed;
    }

    /**
     * Un niveau de plus dans une réponse décodée : échoue si la valeur n'est pas un tableau.
     *
     * @return array<array-key, mixed>
     */
    protected static function arrayOf(mixed $value): array
    {
        self::assertIsArray($value);

        return $value;
    }

    /**
     * @phpstan-impure
     */
    protected function statusCode(): int
    {
        return $this->client->getResponse()->getStatusCode();
    }

    protected function seedUser(string $name = 'alice', string $password = self::PASSWORD): User
    {
        $registrar = self::getContainer()->get(UserRegistrar::class);
        self::assertInstanceOf(UserRegistrar::class, $registrar);

        return $registrar->register($name.'@example.test', $name, $password);
    }

    protected function login(string $login = 'alice', string $password = self::PASSWORD, ?string $code = null): void
    {
        $body = ['login' => $login, 'password' => $password];
        if (null !== $code) {
            $body['code'] = $code;
        }
        $this->call('POST', '/web/login', $body);
        self::assertSame(200, $this->statusCode(), 'login failed');
    }

    protected function seedAndLogin(string $name = 'alice'): User
    {
        $user = $this->seedUser($name);
        $this->login($name);

        return $user;
    }
}
