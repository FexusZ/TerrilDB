<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\UserRepository;
use App\Security\Exception\AccountAlreadyExistsException;
use App\Security\Exception\PasswordPolicyException;
use App\Security\UserRegistrar;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Crée le premier compte (l'administrateur) d'une installation vide, sans ouvrir le formulaire d'inscription. Le mot
 * de passe se saisit deux fois, sans écho, jamais en argument (il resterait dans l'historique du shell et la liste des
 * processus). Les comptes suivants passent par les invitations.
 */
#[AsCommand(name: 'app:user:create-first', description: 'Crée le premier compte (administrateur) d’une installation vide.')]
final class CreateFirstAccountCommand extends Command
{
    public function __construct(
        private readonly UserRegistrar $registrar,
        private readonly UserRepository $users,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Adresse e-mail du compte')
            ->addArgument('username', InputArgument::REQUIRED, 'Nom d’utilisateur');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$input->isInteractive()) {
            $io->error('Cette commande demande le mot de passe au clavier : lancez-la dans un terminal.');

            return Command::FAILURE;
        }
        if ($this->users->count([]) > 0) {
            $io->error('Un compte existe déjà : les comptes suivants se créent par invitation (administration > invitations).');

            return Command::FAILURE;
        }

        $email = $input->getArgument('email');
        $username = $input->getArgument('username');
        if (!\is_string($email) || !\is_string($username)) {
            return Command::INVALID;
        }
        if (false === filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            $io->error('Ce n’est pas une adresse e-mail valide.');

            return Command::FAILURE;
        }

        $password = $io->askHidden('Mot de passe');
        $confirmation = $io->askHidden('Mot de passe (confirmation)');
        if (!\is_string($password) || $password !== $confirmation) {
            $io->error('Les deux mots de passe ne correspondent pas.');

            return Command::FAILURE;
        }

        try {
            $this->registrar->register($email, $username, $password);
        } catch (PasswordPolicyException $e) {
            $io->error('Mot de passe refusé : '.$e->getMessage());

            return Command::FAILURE;
        } catch (AccountAlreadyExistsException) {
            $io->error('Ce compte existe déjà.');

            return Command::FAILURE;
        }

        $io->success('Compte administrateur créé. Vous pouvez vous connecter.');

        return Command::SUCCESS;
    }
}
