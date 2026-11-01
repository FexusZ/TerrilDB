<?php

declare(strict_types=1);

namespace App\Tests\Http\Web\Security;

use App\Crypto\KeyDerivation;
use App\Entity\User;
use App\Http\Web\Security\SecurityUser;
use PHPUnit\Framework\TestCase;

final class SecurityUserTest extends TestCase
{
    private const HASH = '$argon2id$v=19$m=16384,t=3,p=1$c29tZXNhbHQ$aGFzaGhhc2hoYXNoaGFzaA';

    private function user(string $name = 'alice'): User
    {
        return new User($name.'@example.test', $name, self::HASH, (new KeyDerivation(opsLimit: 1, memLimit: 8192))->newParameters(), new \DateTimeImmutable('2026-10-29T12:00:00Z'));
    }

    public function testIsIdentifiedByTheAccountId(): void
    {
        $user = $this->user();
        $security = SecurityUser::fromUser($user);

        self::assertSame($user->getId()->toRfc4122(), $security->getUserIdentifier());
        self::assertSame(['ROLE_USER'], $security->getRoles());
    }

    public function testTheSameAccountIsEqualToItself(): void
    {
        $user = $this->user();

        self::assertTrue(SecurityUser::fromUser($user)->isEqualTo(SecurityUser::fromUser($user)));
    }

    public function testAnotherAccountIsNotEqual(): void
    {
        self::assertFalse(SecurityUser::fromUser($this->user('alice'))->isEqualTo(SecurityUser::fromUser($this->user('bob'))));
    }

    public function testAPasswordChangeEndsTheOtherSessions(): void
    {
        $user = $this->user();
        $before = SecurityUser::fromUser($user);

        $user->changePassword('$argon2id$v=19$m=16384,t=3,p=1$b3RoZXJzYWx0$b3RoZXJoYXNob3RoZXJoYXNo', (new KeyDerivation(opsLimit: 1, memLimit: 8192))->newParameters(), null);

        self::assertFalse($before->isEqualTo(SecurityUser::fromUser($user)));
    }

    public function testItDoesNotCarryTheHashItself(): void
    {
        $serialized = serialize(SecurityUser::fromUser($this->user()));

        self::assertStringNotContainsString('argon2id', $serialized);
    }
}
