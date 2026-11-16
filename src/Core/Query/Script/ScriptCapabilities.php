<?php

declare(strict_types=1);

namespace App\Core\Query\Script;

use App\Core\Connection\Engine;
use App\Core\Guard\StatementKind;

/**
 * Ce que le moteur peut garantir pour CE script. `transactional` : il est exécuté dans une transaction ; `atomic` : une
 * erreur annule tout. MySQL et MariaDB valident implicitement avant un DDL : un script qui en contient n'est pas
 * annulable en entier, et son jeu à blanc est impossible ; une écriture dans une table sans transaction (MyISAM, MEMORY, Aria…)
 * ne s'annule pas non plus. ClickHouse n'a pas de transaction.
 */
final readonly class ScriptCapabilities
{
    /**
     * @param list<string> $warnings
     */
    private function __construct(
        public bool $transactional,
        public bool $atomic,
        public bool $dryRunAvailable,
        public ?string $dryRunUnavailableReason,
        public array $warnings,
    ) {
    }

    /**
     * @param list<ScriptStatement> $statements
     */
    public static function for(Engine $engine, array $statements): self
    {
        if (Engine::PostgreSql === $engine) {
            return new self(true, true, true, null, []);
        }
        if (Engine::ClickHouse === $engine) {
            return new self(false, false, false, 'no_transaction', ['no_transaction']);
        }

        $ddl = array_filter($statements, static fn (ScriptStatement $s): bool => $s->commitsImplicitly);
        $unsafeTables = array_filter($statements, static fn (ScriptStatement $s): bool => $s->writesNonTransactional);
        if ([] === $ddl) {
            // Une table sans transaction garde ce qu'on y écrit, même après ROLLBACK : ni annulation ni jeu à blanc honnêtes.
            return [] === $unsafeTables
                ? new self(true, true, true, null, [])
                : new self(true, false, false, 'non_transactional_tables', ['non_transactional_tables']);
        }
        $data = array_filter($statements, static fn (ScriptStatement $s): bool => !$s->commitsImplicitly && StatementKind::Write === $s->kind);

        $warnings = [[] === $data ? 'ddl_not_transactional' : 'mixed_data_and_ddl'];
        if ([] !== $unsafeTables) {
            $warnings[] = 'non_transactional_tables';
        }

        return new self(true, false, false, 'implicit_commit', $warnings);
    }
}
