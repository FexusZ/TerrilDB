<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\AuditEventRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Supprime les événements d'audit plus vieux que la durée de conservation (365 jours par défaut, réglable avec
 * TERRILDB_AUDIT_RETENTION_DAYS). À planifier (cron) : le journal ne se purge pas tout seul.
 */
#[AsCommand(name: 'app:audit:purge', description: 'Supprime les événements d’audit plus anciens que la durée de conservation.')]
final class AuditPurgeCommand extends Command
{
    public function __construct(
        private readonly AuditEventRepository $events,
        private readonly ClockInterface $clock,
        #[Autowire('%env(int:TERRILDB_AUDIT_RETENTION_DAYS)%')] private readonly int $defaultDays,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('days', null, InputOption::VALUE_REQUIRED, 'Durée de conservation en jours', null);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $raw = $input->getOption('days');
        $days = null === $raw ? $this->defaultDays : (\is_string($raw) && 1 === preg_match('/^\d+$/D', $raw) ? (int) $raw : 0);
        if ($days < 1) {
            $output->writeln('<error>La durée de conservation doit être un nombre entier de jours, au moins 1.</error>');

            return Command::INVALID;
        }

        $limit = \DateTimeImmutable::createFromInterface($this->clock->now())->modify(\sprintf('-%d days', $days));
        $removed = $this->events->purgeOlderThan($limit);
        $output->writeln(\sprintf('%d événement(s) d’audit supprimé(s) (conservation : %d jours).', $removed, $days));

        return Command::SUCCESS;
    }
}
