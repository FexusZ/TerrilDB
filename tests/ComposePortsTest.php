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
}
