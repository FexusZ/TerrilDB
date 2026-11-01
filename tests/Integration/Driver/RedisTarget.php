<?php

declare(strict_types=1);

namespace App\Tests\Integration\Driver;

use PHPUnit\Framework\Assert;
use PHPUnit\Framework\SkippedWithMessageException;
use Predis\Client;
use Predis\PredisException;

/**
 * La cible Redis de test (décrite par TARGET_REDIS_URL) : une base réservée aux tests, vidée avant et après chaque
 * test qui s'en sert. Un garde-fou refuse de vider une autre base que la 15.
 */
final class RedisTarget
{
    public const ENV = 'TARGET_REDIS_URL';
    private const TEST_DATABASE = '15';

    public static function client(): Client
    {
        $parameters = TargetDatabase::parameters(self::ENV);
        Assert::assertSame(self::TEST_DATABASE, $parameters->database, 'The Redis test target must use the reserved database.');

        $client = new Client([
            'scheme' => 'tcp',
            'host' => $parameters->host,
            'port' => $parameters->port,
            'username' => $parameters->username,
            'password' => $parameters->password(),
            'database' => (int) $parameters->database,
            'timeout' => 3.0,
        ]);
        try {
            $client->ping();
        } catch (PredisException) {
            throw new SkippedWithMessageException('The Redis target is unreachable (docker compose --profile targets up -d --wait).');
        }

        return $client;
    }

    /**
     * Une base vide, puis un jeu de clés couvrant chaque type.
     */
    public static function seeded(): Client
    {
        $client = self::client();
        $client->flushdb();
        $client->set('user:1', 'Alice');
        $client->set('user:2', 'Bruno');
        $client->setex('session:abc', 3600, 'token-value');
        $client->hmset('profile:1', ['name' => 'Alice', 'country' => 'FR']);
        $client->rpush('queue', ['a', 'b', 'c']);
        $client->sadd('tags', ['red', 'green']);
        $client->zadd('scores', ['alice' => 10, 'bruno' => 7.5]);
        $client->xadd('events', ['kind' => 'login', 'user' => '1'], '1-1');
        $client->set('日本語:キー', 'unicode');

        return $client;
    }
}
