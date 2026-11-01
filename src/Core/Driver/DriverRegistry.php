<?php

declare(strict_types=1);

namespace App\Core\Driver;

use App\Core\Connection\Engine;
use App\Core\Driver\Exception\UnsupportedEngineException;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final readonly class DriverRegistry
{
    /** @var array<string, Driver> */
    private array $byEngine;

    /**
     * @param iterable<Driver> $drivers
     */
    public function __construct(#[AutowireIterator('app.driver')] iterable $drivers)
    {
        $byEngine = [];
        foreach ($drivers as $driver) {
            foreach ($driver->engines() as $engine) {
                if (isset($byEngine[$engine->value])) {
                    throw new \LogicException(\sprintf('Two drivers serve the engine "%s".', $engine->value));
                }
                $byEngine[$engine->value] = $driver;
            }
        }

        $this->byEngine = $byEngine;
    }

    public function for(Engine $engine): Driver
    {
        return $this->byEngine[$engine->value] ?? throw UnsupportedEngineException::for($engine);
    }
}
