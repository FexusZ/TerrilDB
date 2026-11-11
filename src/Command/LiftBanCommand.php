<?php

declare(strict_types=1);

namespace App\Command;

use App\Http\Web\Security\LoginLockout;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Lève le bannissement d'une adresse (trois comptes bloqués depuis elle, voir LoginLockout), par exemple quand
 * l'adresse est celle d'un réseau partagé. Le palier est gardé : une récidive reste plus longue.
 */
#[AsCommand(name: 'app:ban:lift', description: 'Lève le bannissement d’une adresse après des échecs de connexion répétés.')]
final class LiftBanCommand extends Command
{
    public function __construct(private readonly LoginLockout $lockout)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('address', InputArgument::REQUIRED, 'Adresse IP bannie');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $address = $input->getArgument('address');
        if (!\is_string($address) || false === filter_var($address, \FILTER_VALIDATE_IP)) {
            $output->writeln('<error>Ce n’est pas une adresse IP valide.</error>');

            return Command::FAILURE;
        }

        $this->lockout->liftBan($address);
        $output->writeln('Bannissement levé pour cette adresse (le palier est conservé).');

        return Command::SUCCESS;
    }
}
