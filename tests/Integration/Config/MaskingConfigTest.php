<?php

declare(strict_types=1);

namespace App\Tests\Integration\Config;

use App\Core\Diagnostics\Masking;
use App\Http\Web\DiagnosticsController;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[Group('integration')]
final class MaskingConfigTest extends KernelTestCase
{
    protected function tearDown(): void
    {
        putenv('TERRILDB_MASKING');
        unset($_ENV['TERRILDB_MASKING'], $_SERVER['TERRILDB_MASKING']);
        parent::tearDown();
    }

    public function testTheInfrastructureIsMaskedByDefault(): void
    {
        self::assertTrue($this->masking()->enabled);
    }

    public function testItCanBeSwitchedOffByTheEnvironment(): void
    {
        $_ENV['TERRILDB_MASKING'] = $_SERVER['TERRILDB_MASKING'] = '0';

        self::assertFalse($this->masking()->enabled);
    }

    private function masking(): Masking
    {
        self::bootKernel();
        $controller = self::getContainer()->get(DiagnosticsController::class);
        self::assertInstanceOf(DiagnosticsController::class, $controller);

        $property = new \ReflectionProperty($controller, 'masking');
        $masking = $property->getValue($controller);
        self::assertInstanceOf(Masking::class, $masking);

        return $masking;
    }
}
