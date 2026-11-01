<?php

declare(strict_types=1);

namespace App\Tests\ApiToken;

use App\ApiToken\ApiTokenSecret;
use App\ApiToken\Exception\InvalidApiTokenException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class ApiTokenSecretTest extends TestCase
{
    public function testAGeneratedTokenCarriesItsIdentifierAndAFreshSecret(): void
    {
        $id = Uuid::v7();

        $first = ApiTokenSecret::generate($id);
        $second = ApiTokenSecret::generate($id);

        self::assertTrue($first->id->equals($id));
        self::assertNotSame($first->secret(), $second->secret());
        self::assertMatchesRegularExpression('/^tdb_[0-9a-f]{32}_[A-Za-z0-9_-]{43}$/D', $first->toString());
    }

    public function testItParsesBackWhatItPrinted(): void
    {
        $token = ApiTokenSecret::generate(Uuid::v7());

        $parsed = ApiTokenSecret::parse($token->toString());

        self::assertTrue($parsed->id->equals($token->id));
        self::assertSame($token->secret(), $parsed->secret());
        self::assertSame($token->toString(), $parsed->toString());
    }

    public function testTheVerifierIsDerivedFromTheSecretOnly(): void
    {
        $token = ApiTokenSecret::generate(Uuid::v7());
        $parsed = ApiTokenSecret::parse($token->toString());

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', $token->verifier());
        self::assertSame($token->verifier(), $parsed->verifier());
        self::assertNotSame($token->verifier(), ApiTokenSecret::generate($token->id)->verifier());
        self::assertStringNotContainsString($token->secret(), $token->verifier());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformed(): iterable
    {
        yield 'empty' => [''];
        yield 'no prefix' => ['0190a5c3b2a47e1c8f3d5a6b7c8d9e0f_'.str_repeat('a', 43)];
        yield 'wrong prefix' => ['tok_0190a5c3b2a47e1c8f3d5a6b7c8d9e0f_'.str_repeat('a', 43)];
        yield 'short id' => ['tdb_0190a5c3_'.str_repeat('a', 43)];
        yield 'uppercase id' => ['tdb_0190A5C3B2A47E1C8F3D5A6B7C8D9E0F_'.str_repeat('a', 43)];
        yield 'short secret' => ['tdb_0190a5c3b2a47e1c8f3d5a6b7c8d9e0f_abc'];
        yield 'long secret' => ['tdb_0190a5c3b2a47e1c8f3d5a6b7c8d9e0f_'.str_repeat('a', 44)];
        yield 'bad characters' => ['tdb_0190a5c3b2a47e1c8f3d5a6b7c8d9e0f_'.str_repeat('a', 42).'!'];
        yield 'trailing newline' => ['tdb_0190a5c3b2a47e1c8f3d5a6b7c8d9e0f_'.str_repeat('a', 43)."\n"];
        yield 'bearer left in' => ['Bearer tdb_0190a5c3b2a47e1c8f3d5a6b7c8d9e0f_'.str_repeat('a', 43)];
    }

    #[DataProvider('malformed')]
    public function testMalformedTokensAreRefused(string $token): void
    {
        $this->expectException(InvalidApiTokenException::class);

        ApiTokenSecret::parse($token);
    }

    public function testTheSecretNeverShowsInDumpsNorSerialization(): void
    {
        $token = ApiTokenSecret::generate(Uuid::v7());

        self::assertStringNotContainsString($token->secret(), print_r($token, true));
        ob_start();
        var_dump($token);
        self::assertStringNotContainsString($token->secret(), (string) ob_get_clean());
        $this->expectException(\LogicException::class);
        serialize($token);
    }

    public function testErrorMessagesNeverEchoTheToken(): void
    {
        try {
            ApiTokenSecret::parse('tdb_secret-looking-value');
            self::fail('Expected a refusal.');
        } catch (InvalidApiTokenException $e) {
            self::assertStringNotContainsString('secret-looking', $e->getMessage());
        }
    }
}
