<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\AccountAdministration;
use App\Security\Exception\CannotDisableAccountException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Uid\Uuid;

#[Group('integration')]
final class AccountsWebTest extends WebApiTestCase
{
    private function makeAdmin(User $user): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entityManager->getConnection()->executeStatement("UPDATE app_user SET role = 'admin' WHERE id = ?", [$user->getId()->toRfc4122()]);
    }

    /**
     * @return array<mixed>
     */
    private function accountNamed(string $username): array
    {
        $accounts = self::arrayOf($this->call('GET', '/web/accounts')['accounts']);
        foreach ($accounts as $account) {
            $account = self::arrayOf($account);
            if ($account['username'] === $username) {
                return $account;
            }
        }
        self::fail('No account named '.$username);
    }

    public function testRoutesRequireASession(): void
    {
        foreach ([['GET', '/web/accounts'], ['POST', '/web/accounts/00000000-0000-7000-8000-000000000000/disable'], ['POST', '/web/accounts/00000000-0000-7000-8000-000000000000/enable']] as [$method, $uri]) {
            $this->call($method, $uri);
            self::assertSame(401, $this->statusCode(), $uri);
        }
    }

    public function testAMemberIsForbiddenEverywhere(): void
    {
        $this->seedUser('alice');
        $bob = $this->seedUser('bob');
        $this->login('bob');

        foreach ([['GET', '/web/accounts'], ['POST', '/web/accounts/'.$bob->getId()->toRfc4122().'/disable'], ['POST', '/web/accounts/'.$bob->getId()->toRfc4122().'/enable']] as [$method, $uri]) {
            $body = $this->call($method, $uri);
            self::assertSame(403, $this->statusCode(), $uri);
            self::assertSame('forbidden', $body['error']);
        }
    }

    public function testTheListShowsOnlyTheAgreedFields(): void
    {
        $this->seedUser('alice');
        $this->seedUser('bob');
        $this->login('alice');

        $body = $this->call('GET', '/web/accounts');

        self::assertSame(200, $this->statusCode());
        $accounts = self::arrayOf($body['accounts']);
        self::assertCount(2, $accounts);
        $alice = $this->accountNamed('alice');
        self::assertSame(['current', 'disabled', 'id', 'lastLoginAt', 'role', 'totpEnabled', 'username'], $this->sorted($alice));
        self::assertSame('admin', $alice['role']);
        self::assertTrue($alice['current']);
        self::assertNotNull($alice['lastLoginAt']);
        $bob = $this->accountNamed('bob');
        self::assertSame('member', $bob['role']);
        self::assertFalse($bob['current']);
        self::assertFalse($bob['disabled']);
        self::assertNull($bob['lastLoginAt']);
        self::assertStringNotContainsString('example.test', json_encode($body, \JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<mixed> $row
     *
     * @return list<string>
     */
    private function sorted(array $row): array
    {
        $keys = array_keys($row);
        sort($keys);

        return $keys;
    }

    public function testAnAdministratorDisablesAndEnablesAMemberAndEveryChangeIsAudited(): void
    {
        $alice = $this->seedAndLogin('alice');
        $bob = $this->seedUser('bob');
        $id = $bob->getId()->toRfc4122();

        $disabled = $this->call('POST', '/web/accounts/'.$id.'/disable');
        self::assertSame(200, $this->statusCode());
        self::assertTrue(self::arrayOf($disabled['account'])['disabled']);
        self::assertTrue($this->accountNamed('bob')['disabled']);

        $this->call('POST', '/web/login', ['login' => 'bob', 'password' => self::PASSWORD]);
        self::assertSame(401, $this->statusCode());

        $enabled = $this->call('POST', '/web/accounts/'.$id.'/enable');
        self::assertSame(200, $this->statusCode());
        self::assertFalse(self::arrayOf($enabled['account'])['disabled']);

        $actions = [];
        foreach ($this->auditEvents($alice, 'account') as $event) {
            $actions[] = $event->getAction()->value;
            self::assertSame($id, $event->getSubjectId()?->toRfc4122());
            self::assertSame([], $event->getDetails());
        }
        self::assertSame(['account.enabled', 'account.disabled'], $actions);
    }

    public function testRepeatingAnActionChangesAndJournalsNothingMore(): void
    {
        $alice = $this->seedAndLogin('alice');
        $bob = $this->seedUser('bob');
        $uri = '/web/accounts/'.$bob->getId()->toRfc4122();

        $this->call('POST', $uri.'/disable');
        $this->call('POST', $uri.'/disable');
        self::assertSame(200, $this->statusCode());
        $this->call('POST', $uri.'/enable');
        $this->call('POST', $uri.'/enable');

        self::assertCount(2, $this->auditEvents($alice, 'account'));
    }

    public function testAnAdministratorCannotDisableThemselves(): void
    {
        $alice = $this->seedAndLogin('alice');

        $body = $this->call('POST', '/web/accounts/'.$alice->getId()->toRfc4122().'/disable');

        self::assertSame(409, $this->statusCode());
        self::assertSame('cannot_disable_self', $body['error']);
        self::assertFalse($this->accountNamed('alice')['disabled']);
    }

    public function testAnotherAdministratorCanBeDisabledButTheLastActiveOneNever(): void
    {
        $alice = $this->seedAndLogin('alice');
        $bob = $this->seedUser('bob');
        $this->makeAdmin($bob);

        $this->call('POST', '/web/accounts/'.$bob->getId()->toRfc4122().'/disable');
        self::assertSame(200, $this->statusCode());

        $administration = self::getContainer()->get(AccountAdministration::class);
        $users = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(AccountAdministration::class, $administration);
        self::assertInstanceOf(UserRepository::class, $users);
        $disabledAdmin = $users->find($bob->getId());
        self::assertNotNull($disabledAdmin);
        try {
            $administration->disable($disabledAdmin, $alice->getId());
            self::fail('The last active administrator must stay enabled.');
        } catch (CannotDisableAccountException $refused) {
            self::assertSame(CannotDisableAccountException::LAST_ADMINISTRATOR, $refused->reason);
        }
        self::assertFalse($this->accountNamed('alice')['disabled']);
    }

    public function testAnUnknownAccountIsNotFound(): void
    {
        $this->seedAndLogin('alice');

        foreach (['disable', 'enable'] as $action) {
            $body = $this->call('POST', '/web/accounts/'.Uuid::v7()->toRfc4122().'/'.$action);
            self::assertSame(404, $this->statusCode());
            self::assertSame('not_found', $body['error']);
        }
    }

    public function testStateChangingRoutesNeedTheRequestHeader(): void
    {
        $this->seedAndLogin('alice');
        $bob = $this->seedUser('bob');

        $this->call('POST', '/web/accounts/'.$bob->getId()->toRfc4122().'/disable', csrf: false);

        self::assertSame(403, $this->statusCode());
        self::assertFalse($this->accountNamed('bob')['disabled']);
    }
}
