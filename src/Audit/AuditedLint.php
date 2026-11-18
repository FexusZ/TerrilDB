<?php

declare(strict_types=1);

namespace App\Audit;

use App\Core\Diagnostics\QueryText;
use App\Core\Driver\TargetConnection;
use App\Core\Guard\SqlDialect;
use App\Core\Optimizer\LintFinding;
use App\Core\Optimizer\QueryLinter;
use App\Core\Schema\TableDefinition;
use Symfony\Component\Uid\Uuid;

/**
 * Le linter de requête et la trace qu'il laisse, qui que soit la porte (interface, API, MCP). Rien n'est envoyé à la base : la
 * seule lecture est celle de la structure d'UNE table (catalogue, jamais la requête de l'utilisateur), et seulement quand une
 * règle en a besoin. Le texte du brouillon ne se garde que pour une lecture que le linter juge, masqué puis coupé : un
 * CREATE USER … IDENTIFIED BY collé dans l'éditeur ne doit pas finir un an dans le journal. Le reste : le nombre et la longueur.
 */
final readonly class AuditedLint
{
    /** Au plus ce nombre de conseils rendus (la réponse reste bornée quelle que soit la requête). */
    public const MAX_FINDINGS = 50;

    public function __construct(private QueryLinter $linter, private AuditLog $audit)
    {
    }

    /**
     * @return list<LintFinding>
     */
    public function lint(AuditContext $context, Uuid $connectionId, TargetConnection $target, string $sql): array
    {
        $engine = $target->engine;
        $findings = \array_slice($this->linter->lint($engine, $sql, static fn (string $table): TableDefinition => $target->introspector()->describeTable($table)), 0, self::MAX_FINDINGS);

        $details = [
            'engine' => $engine->value,
            'findings' => \count($findings),
            'rules' => array_map(static fn (LintFinding $f): string => $f->rule->value, $findings),
            'length' => mb_strlen(mb_scrub($sql, 'UTF-8')),
        ];
        if ($this->linter->judges($engine, $sql)) {
            // Valeurs masquées (`?`) et commentaires retirés AVANT de couper : une adresse e-mail ou un numéro dans un WHERE ne se garde pas.
            $text = QueryText::of($sql, SqlDialect::forEngine($engine));
            $details['sql'] = $text->text;
            $details['truncated'] = $text->shortened;
        }
        $this->audit->record($context, AuditAction::QueryLinted, AuditOutcome::Success, $connectionId, $details);

        return $findings;
    }
}
