<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\ScriptRunRepository;
use App\ScriptRun\Channel\ChildSpawner;
use App\ScriptRun\Channel\ScriptRunChannel;
use App\ScriptRun\Channel\ScriptTunnelKeeper;
use App\ScriptRun\Channel\ScriptWorkerServer;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Le worker des exécutions de scripts détachées (#169) : service de longue durée, un conteneur à part (compose.prod.yaml). Il écoute le
 * socket Unix du volume partagé avec l'application, confie chaque exécution à un processus à elle et donne le signe de vie des
 * exécutions qu'il fait tourner (une longue instruction occupe le processus qui joue le script : il ne peut pas le donner lui-même).
 * Une exécution dont le worker meurt perd son signe de vie et est déclarée interrompue au bout de 30 s.
 */
#[AsCommand(name: 'app:script:worker', description: 'Service : joue les exécutions de scripts SQL détachées.')]
final class ScriptWorkerCommand extends Command
{
    private const HEARTBEAT_EVERY_SECONDS = 5;

    /** Service de longue durée : il ne s'arrête que quand son processus est arrêté (SIGTERM de `docker stop`). */
    private bool $running = true;

    public function __construct(
        private readonly ScriptRunRepository $runs,
        private readonly ChildSpawner $spawner,
        private readonly ScriptRunChannel $channel,
        private readonly ScriptTunnelKeeper $tunnels,
        private readonly ClockInterface $clock,
        #[Autowire('%env(TERRILDB_SCRIPT_SOCKET)%')] private readonly string $socketPath,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('max-children', null, InputOption::VALUE_REQUIRED, 'Exécutions jouées en même temps', '4');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $raw = $input->getOption('max-children');
        $max = \is_string($raw) && 1 === preg_match('/^\d+$/D', $raw) ? (int) $raw : 0;
        if ($max < 1 || $max > 32) {
            $output->writeln('<error>--max-children : un nombre entier de 1 à 32.</error>');

            return Command::INVALID;
        }

        // Ce que le worker précédent jouait n'a plus personne pour donner son signe de vie.
        $interrupted = $this->runs->interruptStale(\DateTimeImmutable::createFromInterface($this->clock->now()));
        $server = new ScriptWorkerServer($this->socketPath, $this->channel, $this->spawner, $max, tunnels: $this->tunnels);
        $server->listen();
        $output->writeln(\sprintf('Worker des scripts à l’écoute (%d exécution(s) au plus) ; %d exécution(s) périmée(s) déclarée(s) interrompue(s).', $max, $interrupted));

        $lastBeat = 0;
        while ($this->running) {
            $server->tick(1000);
            $server->renewTunnels();
            if (time() - $lastBeat >= self::HEARTBEAT_EVERY_SECONDS) {
                $ids = array_map(static fn (string $id): \Symfony\Component\Uid\Uuid => \Symfony\Component\Uid\Uuid::fromString($id), $server->liveRunIds());
                $this->runs->beatAll($ids, \DateTimeImmutable::createFromInterface($this->clock->now()));
                $lastBeat = time();
            }
        }

        return Command::SUCCESS;
    }
}
