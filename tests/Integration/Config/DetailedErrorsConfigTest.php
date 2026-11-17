<?php

declare(strict_types=1);

namespace App\Tests\Integration\Config;

use App\Audit\AuditedDataChanges;
use App\Audit\AuditedQueries;
use App\Audit\AuditedSchemaChanges;
use App\Core\Driver\DetailedErrors;
use App\Http\Json\ErrorMapper;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[Group('integration')]
final class DetailedErrorsConfigTest extends KernelTestCase
{
    protected function tearDown(): void
    {
        putenv('TERRILDB_DETAILED_ERRORS');
        unset($_ENV['TERRILDB_DETAILED_ERRORS'], $_SERVER['TERRILDB_DETAILED_ERRORS']);
        parent::tearDown();
    }

    public function testTheGenericMessagesAreKeptByDefault(): void
    {
        self::assertFalse($this->setting()->enabled);
    }

    public function testItCanBeSwitchedOnByTheEnvironment(): void
    {
        $_ENV['TERRILDB_DETAILED_ERRORS'] = $_SERVER['TERRILDB_DETAILED_ERRORS'] = '1';

        self::assertTrue($this->setting()->enabled);
    }

    public function testTheMapperAndTheJournalsAllReadTheSameSetting(): void
    {
        $_ENV['TERRILDB_DETAILED_ERRORS'] = $_SERVER['TERRILDB_DETAILED_ERRORS'] = '1';
        self::bootKernel();

        foreach ([ErrorMapper::class, AuditedQueries::class, AuditedDataChanges::class, AuditedSchemaChanges::class] as $service) {
            $object = self::getContainer()->get($service);
            $setting = (new \ReflectionProperty($object, 'detailed'))->getValue($object);
            self::assertInstanceOf(DetailedErrors::class, $setting, $service);
            self::assertTrue($setting->enabled, $service);
        }
    }

    private function setting(): DetailedErrors
    {
        self::bootKernel();
        $mapper = self::getContainer()->get(ErrorMapper::class);
        self::assertInstanceOf(ErrorMapper::class, $mapper);
        $setting = (new \ReflectionProperty($mapper, 'detailed'))->getValue($mapper);
        self::assertInstanceOf(DetailedErrors::class, $setting);

        return $setting;
    }
}
