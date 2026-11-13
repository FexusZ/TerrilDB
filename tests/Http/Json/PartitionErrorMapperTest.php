<?php

declare(strict_types=1);

namespace App\Tests\Http\Json;

use App\Core\Schema\Change\Exception\UnsupportedPartitionChangeException;
use App\Http\Json\ErrorMapper;
use PHPUnit\Framework\TestCase;

final class PartitionErrorMapperTest extends TestCase
{
    public function testARefusedChangeOnAPartitionedTableNamesItsReasonAndSubject(): void
    {
        $response = (new ErrorMapper())->map(new UnsupportedPartitionChangeException('partition_column', 'created_on'));

        self::assertNotNull($response);
        self::assertSame(422, $response->status);
        self::assertSame('unsupported_partition_change', $response->code);
        self::assertSame(['reason' => 'partition_column', 'subject' => 'created_on'], $response->extra);
    }
}
