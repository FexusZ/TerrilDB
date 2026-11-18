<?php

declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/** Les ports de développement ne s'ouvrent que sur la machine locale (HTTP clair, bases de test aux mots de passe jetables). */
final class ComposePortsTest extends TestCase
{
    public function testEveryPublishedPortIsBoundToLoopback(): void
    {
        $compose = Yaml::parseFile(\dirname(__DIR__).'/compose.yaml');
        self::assertIsArray($compose);
        $services = $compose['services'] ?? null;
        self::assertIsArray($services);

        $published = 0;
        foreach ($services as $name => $service) {
            self::assertIsArray($service);
            foreach ((array) ($service['ports'] ?? []) as $port) {
                ++$published;
                self::assertIsString($port);
                self::assertStringStartsWith('127.0.0.1:', $port, \sprintf('Le port « %s » du service « %s » est ouvert à tout le réseau.', $port, \is_string($name) ? $name : ''));
            }
        }
        self::assertGreaterThan(0, $published);
    }

    /** Les paires de réplication ont leur profil à part, des noms préfixés « repl- », et ne touchent ni aux cibles ni à leurs volumes. */
    public function testReplicationServicesLiveInTheirOwnProfile(): void
    {
        $compose = Yaml::parseFile(\dirname(__DIR__).'/compose.yaml');
        self::assertIsArray($compose);
        $services = $compose['services'] ?? null;
        self::assertIsArray($services);

        $found = [];
        foreach ($services as $name => $service) {
            self::assertIsString($name);
            self::assertIsArray($service);
            $profiles = (array) ($service['profiles'] ?? []);
            self::assertSame(str_starts_with($name, 'repl-'), \in_array('replication', $profiles, true), $name);
            if (str_starts_with($name, 'repl-')) {
                self::assertSame(['replication'], $profiles, $name);
                self::assertNotNull($service['healthcheck'] ?? null, $name.' needs a healthcheck.');
                $found[] = $name;
            }
        }
        foreach (['repl-pg-primary', 'repl-pg-replica', 'repl-redis-primary', 'repl-redis-replica', 'repl-clickhouse', 'repl-mysql-source', 'repl-mysql-replica', 'repl-mysql-semisync', 'repl-mariadb-source', 'repl-mariadb-replica', 'repl-mariadb-binlog'] as $expected) {
            self::assertContains($expected, $found);
        }
    }
}
