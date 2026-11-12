<?php

declare(strict_types=1);

namespace App\Tests\Http\Json;

use App\Core\Connection\Exception\ForbiddenBastionException;
use App\Core\Connection\Tunnel\TunnelFailedException;
use App\Http\Json\ErrorMapper;
use PHPUnit\Framework\TestCase;

final class TunnelErrorMapperTest extends TestCase
{
    public function testAForbiddenBastionIsA403WithAStableCode(): void
    {
        $response = (new ErrorMapper())->map(ForbiddenBastionException::create());

        self::assertSame(403, $response?->status);
        self::assertSame('forbidden_bastion', $response->code);
    }

    public function testATunnelFailureIsA502WithItsReasonAndNothingElse(): void
    {
        $response = (new ErrorMapper())->map(new TunnelFailedException(TunnelFailedException::HOST_KEY_CHANGED));

        self::assertSame(502, $response?->status);
        self::assertSame('tunnel_failed', $response->code);
        self::assertSame(['reason' => 'host_key_changed'], $response->extra);
    }
}
