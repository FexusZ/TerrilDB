<?php

declare(strict_types=1);

// Sous-processus des tests de concurrence : un administrateur désactive un compte, le résultat est écrit sur la sortie.
// Usage : php disable_account.php <id de l'administrateur> <id de la cible>

use App\Kernel;
use App\Repository\UserRepository;
use App\Security\AccountAdministration;
use App\Security\Exception\CannotDisableAccountException;
use Symfony\Component\Uid\Uuid;

/** @var list<string> $argv */
$argv = $_SERVER['argv'];

require dirname(__DIR__, 3).'/tests/bootstrap.php';

$kernel = new Kernel('test', false);
$kernel->boot();
$container = $kernel->getContainer()->get('test.service_container');
assert($container instanceof Psr\Container\ContainerInterface);
$users = $container->get(UserRepository::class);
$accounts = $container->get(AccountAdministration::class);
assert($users instanceof UserRepository && $accounts instanceof AccountAdministration);

$admin = $users->find(Uuid::fromString($argv[1]));
assert(null !== $admin);

try {
    $accounts->disable($admin, Uuid::fromString($argv[2]));
    echo 'disabled';
} catch (CannotDisableAccountException $refused) {
    echo $refused->reason;
}
