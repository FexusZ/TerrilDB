<?php

declare(strict_types=1);

namespace App\Tests\Integration\Config;

use App\Core\Diagnostics\Replication\InfrastructureMasking;
use App\Http\Web\DiagnosticsController;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[Group('integration')]
final class InfrastructureMaskingConfigTest extends KernelTestCase
{
    protected function tearDown(): void
    {
        putenv('TERRILDB_MASK_INFRASTRUCTURE');
        unset($_ENV['TERRILDB_MASK_INFRASTRUCTURE'], $_SERVER['TERRILDB_MASK_INFRASTRUCTURE']);
        parent::tearDown();
    }

    public function testTheInfrastructureIsMaskedByDefault(): void
    {
        self::assertTrue($this->masking()->enabled);
    }

    public function testItCanBeSwitchedOffByTheEnvironment(): void
    {
        $_ENV['TERRILDB_MASK_INFRASTRUCTURE'] = $_SERVER['TERRILDB_MASK_INFRASTRUCTURE'] = '0';

        self::assertFalse($this->masking()->enabled);
    }

    private function masking(): InfrastructureMasking
    {
        self::bootKernel();
        $controller = self::getContainer()->get(DiagnosticsController::class);
        self::assertInstanceOf(DiagnosticsController::class, $controller);

        $property = new \ReflectionProperty($controller, 'masking');
        $masking = $property->getValue($controller);
        self::assertInstanceOf(InfrastructureMasking::class, $masking);

        return $masking;
    }
}
