<?php

declare(strict_types=1);

namespace App\Core\Query\Script;

use App\Core\Connection\Engine;
use App\Core\Guard\StatementKind;

/**
 * Ce que le moteur peut garantir pour CE script. `transactional` : il est exécuté dans une transaction ; `atomic` : une
 * erreur annule tout. MySQL et MariaDB valident implicitement avant un DDL : un script qui en contient n'est pas
 * annulable en entier, et son jeu à blanc est impossible. ClickHouse n'a pas de transaction.
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
        if ([] === $ddl) {
            return new self(true, true, true, null, []);
        }
        $data = array_filter($statements, static fn (ScriptStatement $s): bool => !$s->commitsImplicitly && StatementKind::Write === $s->kind);

        return new self(true, false, false, 'implicit_commit', [[] === $data ? 'ddl_not_transactional' : 'mixed_data_and_ddl']);
    }
}
