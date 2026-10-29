<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\Exception\PasswordPolicyException;
use App\Security\PasswordPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PasswordPolicyTest extends TestCase
{
    public function testLimitsAreExposedForFormConstraints(): void
    {
        self::assertSame(12, PasswordPolicy::MIN_LENGTH);
        self::assertSame(4096, PasswordPolicy::MAX_LENGTH);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function acceptable(): iterable
    {
        yield '12 caractères' => [str_repeat('a', 12)];
        yield 'phrase de passe' => ['correct horse battery staple'];
        yield 'maximum' => [str_repeat('a', 4096)];
        yield '12 caractères accentués (24 octets)' => [str_repeat('é', 12)];
        yield '12 emojis' => [str_repeat('🔑', 12)];
    }

    #[DataProvider('acceptable')]
    public function testAcceptsPasswordsWithinTheLimits(string $password): void
    {
        (new PasswordPolicy())->assertAcceptable($password);

        $this->expectNotToPerformAssertions();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function tooShort(): iterable
    {
        yield 'vide' => [''];
        yield '11 caractères' => [str_repeat('a', 11)];
        yield '6 caractères accentués (12 octets)' => [str_repeat('é', 6)];
    }

    #[DataProvider('tooShort')]
    public function testRejectsPasswordsShorterThanTheMinimum(string $password): void
    {
        $this->expectException(PasswordPolicyException::class);
        $this->expectExceptionMessage('at least 12 characters');

        (new PasswordPolicy())->assertAcceptable($password);
    }

    public function testRejectsPasswordsLongerThanTheMaximum(): void
    {
        $this->expectException(PasswordPolicyException::class);
        $this->expectExceptionMessage('at most 4096 characters');

        (new PasswordPolicy())->assertAcceptable(str_repeat('a', 4097));
    }

    public function testRejectsPasswordsMadeOfWhitespaceOnly(): void
    {
        $this->expectException(PasswordPolicyException::class);
        $this->expectExceptionMessage('whitespace');

        (new PasswordPolicy())->assertAcceptable(str_repeat(' ', 20));
    }

    public function testRejectsInvalidUtf8(): void
    {
        $this->expectException(PasswordPolicyException::class);

        (new PasswordPolicy())->assertAcceptable("\xff\xfe".str_repeat('a', 20));
    }

    public function testViolationNeverEchoesThePassword(): void
    {
        try {
            (new PasswordPolicy())->assertAcceptable('tiny-secret');
            self::fail('Expected a policy violation.');
        } catch (PasswordPolicyException $e) {
            self::assertStringNotContainsString('tiny-secret', $e->getMessage());
        }
    }
}
