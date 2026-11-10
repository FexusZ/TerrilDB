<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Command\CreateFirstAccountCommand;
use App\Entity\Role;
use App\Repository\UserRepository;
use App\Security\UserRegistrar;
use App\Tests\Integration\Persistence\DatabaseTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Console\Tester\CommandTester;

#[Group('integration')]
final class CreateFirstAccountCommandTest extends DatabaseTestCase
{
    private const PASSWORD = 'correct horse battery staple';

    private UserRepository $users;
    private CommandTester $tester;

    protected function setUp(): void
    {
        parent::setUp();

        $users = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        $registrar = self::getContainer()->get(UserRegistrar::class);
        self::assertInstanceOf(UserRegistrar::class, $registrar);
        $this->users = $users;
        $this->tester = new CommandTester(new CreateFirstAccountCommand($registrar, $users));
    }

    public function testCreatesTheAdministratorOfAnEmptyInstallation(): void
    {
        $this->tester->setInputs([self::PASSWORD, self::PASSWORD]);

        $status = $this->tester->execute(['email' => 'admin@example.test', 'username' => 'admin'], ['interactive' => true]);

        self::assertSame(0, $status);
        $user = $this->users->findByLogin('admin');
        self::assertNotNull($user);
        self::assertSame(Role::Admin, $user->getRole());
    }

    public function testPasswordIsAskedTwiceAndNeverEchoed(): void
    {
        $this->tester->setInputs([self::PASSWORD, self::PASSWORD]);

        $status = $this->tester->execute(['email' => 'admin@example.test', 'username' => 'admin'], ['interactive' => true]);

        self::assertSame(0, $status);
        self::assertStringNotContainsString(self::PASSWORD, $this->tester->getDisplay());
    }

    public function testRefusesWhenTheTwoPasswordsDiffer(): void
    {
        $this->tester->setInputs([self::PASSWORD, self::PASSWORD.'x']);

        $status = $this->tester->execute(['email' => 'admin@example.test', 'username' => 'admin'], ['interactive' => true]);

        self::assertSame(1, $status);
        self::assertNull($this->users->findByLogin('admin'));
    }

    public function testRefusesAWeakPassword(): void
    {
        $this->tester->setInputs(['short', 'short']);

        $status = $this->tester->execute(['email' => 'admin@example.test', 'username' => 'admin'], ['interactive' => true]);

        self::assertSame(1, $status);
        self::assertNull($this->users->findByLogin('admin'));
    }

    public function testRefusesToRunWithoutATerminal(): void
    {
        $status = $this->tester->execute(['email' => 'admin@example.test', 'username' => 'admin'], ['interactive' => false]);

        self::assertSame(1, $status);
        self::assertNull($this->users->findByLogin('admin'));
    }

    public function testOnlyTheFirstAccountCanBeCreatedThisWay(): void
    {
        $this->tester->setInputs([self::PASSWORD, self::PASSWORD]);
        $this->tester->execute(['email' => 'admin@example.test', 'username' => 'admin'], ['interactive' => true]);

        $this->tester->setInputs([self::PASSWORD, self::PASSWORD]);
        $status = $this->tester->execute(['email' => 'second@example.test', 'username' => 'second'], ['interactive' => true]);

        self::assertSame(1, $status);
        self::assertStringContainsString('invitation', $this->tester->getDisplay());
        self::assertNull($this->users->findByLogin('second'));
    }

    public function testRefusesAnAddressThatIsNotOne(): void
    {
        $this->tester->setInputs([self::PASSWORD, self::PASSWORD]);

        $status = $this->tester->execute(['email' => 'pas une adresse', 'username' => 'admin'], ['interactive' => true]);

        self::assertSame(1, $status);
        self::assertNull($this->users->findByLogin('admin'));
    }
}
