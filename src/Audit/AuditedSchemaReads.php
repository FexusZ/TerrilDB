<?php

declare(strict_types=1);

namespace App\Audit;

use App\Core\Driver\TargetConnection;
use App\Core\Schema\DatabaseSummary;
use App\Core\Schema\SchemaSummary;
use Symfony\Component\Uid\Uuid;

/**
 * Lit la liste des bases ou des schémas d'un serveur pour un jeton d'API ou le MCP et en garde la trace (qui, quelle connexion,
 * combien de lignes) : jamais la liste elle-même.
 */
final readonly class AuditedSchemaReads
{
    public function __construct(private AuditLog $audit)
    {
    }

    /**
     * @return list<DatabaseSummary>
     */
    public function databases(AuditContext $context, Uuid $connectionId, TargetConnection $target): array
    {
        $databases = $target->introspector()->listDatabases();
        $this->audit->record($context, AuditAction::SchemaRead, AuditOutcome::Success, $connectionId, ['topic' => 'databases', 'engine' => $target->engine->value, 'rows' => \count($databases)]);

        return $databases;
    }

    /**
     * @return list<SchemaSummary>
     */
    public function schemas(AuditContext $context, Uuid $connectionId, TargetConnection $target): array
    {
        $schemas = $target->introspector()->listSchemas();
        $this->audit->record($context, AuditAction::SchemaRead, AuditOutcome::Success, $connectionId, ['topic' => 'schemas', 'engine' => $target->engine->value, 'rows' => \count($schemas)]);

        return $schemas;
    }
}
