<?php

declare(strict_types=1);

namespace App\Audit;

use App\Core\Driver\TargetConnection;
use App\Core\Schema\Change\ChangePlan;
use App\Core\Schema\Change\ChangePreview;
use App\Core\Schema\Change\DdlStatement;
use App\Core\Schema\Change\Exception\SchemaChangeFailedException;
use App\Core\Schema\Change\SchemaChangeService;
use App\Core\Schema\Change\TableDraft;
use Symfony\Component\Uid\Uuid;

/**
 * Applique un changement de structure et en garde la trace : le SQL exact qui est parti (ou qui a échoué) est conservé, comme
 * pour les requêtes. Montrer un aperçu n'écrit rien et n'est pas journalisé ; une simple demande de confirmation non plus.
 */
final readonly class AuditedSchemaChanges
{
    private const MAX_ERROR_LENGTH = 500;

    public function __construct(
        private SchemaChangeService $changes,
        private AuditLog $audit,
    ) {
    }

    public function preview(TargetConnection $target, string $table, ?TableDraft $draft, ?string $fingerprint): ChangePreview
    {
        return $this->changes->preview($target, $table, $draft, $fingerprint);
    }

    /**
     * @return array{ChangePlan, ?string}
     */
    public function apply(AuditContext $context, Uuid $connectionId, TargetConnection $target, string $table, ?TableDraft $draft, string $fingerprint, bool $confirmed): array
    {
        try {
            [$plan, $after] = $this->changes->apply($target, $table, $draft, $fingerprint, $confirmed);
        } catch (SchemaChangeFailedException $e) {
            $this->audit->record($context, AuditAction::SchemaChangeFailed, AuditOutcome::Failed, $connectionId, [
                'table' => $table,
                'engine' => $target->engine->value,
                'sql' => self::sql($e->applied === [] ? [$e->failed] : [...$e->applied, $e->failed]),
                'failedSql' => $e->failed->sql,
                'applied' => \count($e->applied),
                'error' => mb_substr($e->getMessage(), 0, self::MAX_ERROR_LENGTH),
            ]);

            throw $e;
        }

        if ([] !== $plan->statements) {
            $this->audit->record($context, AuditAction::SchemaChanged, AuditOutcome::Success, $connectionId, [
                'table' => $table,
                'engine' => $target->engine->value,
                'sql' => self::sql($plan->statements),
                'statements' => \count($plan->statements),
                'destructive' => $plan->hasDestructive(),
                'transactional' => $plan->transactional,
                'confirmed' => $confirmed,
            ]);
        }

        return [$plan, $after];
    }

    /**
     * @param list<DdlStatement> $statements
     */
    private static function sql(array $statements): string
    {
        return implode(";\n", array_map(static fn (DdlStatement $s): string => $s->sql, $statements));
    }
}
