<?php

declare(strict_types=1);

namespace App\Command;

use App\ScriptRun\Exception\InvalidScriptRunRequestException;
use App\ScriptRun\ScriptRunExecutor;
use App\ScriptRun\ScriptRunRequest;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Interne : joue UNE exécution détachée dont le message arrive par l'entrée standard (lancé par le worker, jamais à la main). Un
 * terminal n'est pas une entrée valable : les identifiants ne se tapent pas.
 */
#[AsCommand(name: 'app:script:run-child', description: 'Interne : joue une exécution de script détachée (message sur l’entrée standard).', hidden: true)]
final class ScriptRunChildCommand extends Command
{
    public function __construct(private readonly ScriptRunExecutor $executor)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (\function_exists('posix_isatty') && posix_isatty(\STDIN)) {
            $output->writeln('<error>Commande interne : le message arrive par l’entrée standard du worker.</error>');

            return Command::INVALID;
        }
        $json = stream_get_contents(\STDIN, ScriptRunRequest::MAX_JSON_BYTES + 1);
        try {
            $request = ScriptRunRequest::fromJson(false === $json ? '' : $json);
        } catch (InvalidScriptRunRequestException $e) {
            $output->writeln('<error>'.$e->getMessage().'</error>');

            return Command::INVALID;
        }
        $this->executor->run($request);

        return Command::SUCCESS;
    }
}
