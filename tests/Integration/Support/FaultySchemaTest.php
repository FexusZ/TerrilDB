<?php

declare(strict_types=1);

namespace App\Tests\Integration\Support;

use App\Core\Connection\Engine;
use App\Core\Optimizer\Finding;
use App\Core\Optimizer\SchemaAuditor;
use App\Core\Schema\SchemaIntrospector;
use App\Core\Schema\TableDefinition;
use App\Core\Schema\TableKind;
use App\Driver\MySql\MySqlIntrospector;
use App\Driver\PostgreSql\PostgreSqlIntrospector;
use App\Tests\Integration\Driver\TargetDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Le schéma « fautif » : chaque défaut planté est signalé par l'audit comme attendu, ceux qu'aucune règle ne juge sont vus tels
 * quels par l'introspection, et le schéma partagé ne change pas (rien de plus n'est signalé sur lui).
 */
#[Group('integration')]
final class FaultySchemaTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function targets(): iterable
    {
        return TargetDatabase::relational();
    }

    #[DataProvider('targets')]
    public function testTheAuditReportsEveryPlantedFault(string $env): void
    {
        $schema = FaultySchema::create($env);
        try {
            $found = $this->summary($schema, $this->findings($schema->session, $schema));
        } finally {
            $schema->drop();
        }

        $expected = [
            'counter_near_limit:counter:id',
            'float_amount:price_float:price',
            'missing_primary_key:copy:',
            'missing_primary_key:junction:',
            'redundant_index:dup_idx:%dup_idx_a2',
            'redundant_index:prefix_idx:%prefix_idx_a',
            'text_primary_key:uuid_key:id',
        ];
        if (Engine::PostgreSql === TargetDatabase::engine($env)) {
            $expected[] = 'foreign_key_type_mismatch:mismatch:ref';
            $expected[] = 'foreign_key_without_index:fk_noindex:parent_id';
        }
        sort($expected);

        self::assertSame($expected, $found);
    }

    #[DataProvider('targets')]
    public function testFaultsWithoutARuleAreVisibleToTheIntrospectionAndTheAuditStaysSilent(string $env): void
    {
        $schema = FaultySchema::create($env);
        try {
            $introspector = self::introspector($schema->session);
            $abbreviated = $introspector->describeTable($schema->table('child_abbrev'));
            $twinA = $introspector->describeTable($schema->table('twin_a'));
            $twinB = $introspector->describeTable($schema->table('twin_b'));
            $myIsam = Engine::PostgreSql === $schema->session->engine ? null : $introspector->describeTable($schema->table('myisam'));
            $silent = array_filter($this->findings($schema->session, $schema), static fn (Finding $f): bool => preg_match('/_(child_abbrev|twin_a|twin_b|myisam|collate_a|collate_b|parent|child_ok)$/', $f->table) > 0);
        } finally {
            $schema->drop();
        }

        // La colonne `parent_id` n'est reliée à rien : l'introspection ne voit aucune clé étrangère à auditer.
        self::assertSame([], $abbreviated->foreignKeys);
        self::assertEquals(array_map(static fn ($c): array => [$c->name, $c->type], $twinA->columns), array_map(static fn ($c): array => [$c->name, $c->type], $twinB->columns));
        if (null !== $myIsam) {
            self::assertSame('MyISAM', $myIsam->storageEngine);
            self::assertFalse($myIsam->transactional);
        }
        self::assertSame([], array_values($silent));
    }

    #[DataProvider('targets')]
    public function testTheSharedSchemaEmitsNothingMoreAndNothingIsLeftBehind(string $env): void
    {
        $before = $this->summary(null, $this->findings(DatasetSession::open($env), null));
        $schema = FaultySchema::create($env);
        try {
            $shared = $this->findings($schema->session, null);
        } finally {
            $schema->drop();
        }

        self::assertSame($before, $this->summary(null, $shared));
        self::assertSame([], array_filter($before, static fn (string $line): bool => str_contains($line, 'tdb_ds_')));
        self::assertSame([], SkewedTable::leftovers($env));
    }

    private static function introspector(DatasetSession $session): SchemaIntrospector
    {
        return Engine::PostgreSql === $session->engine
            ? new PostgreSqlIntrospector($session->connection())
            : new MySqlIntrospector($session->connection());
    }

    /**
     * Les constats sur les tables du schéma fautif (`$schema` donné) ou sur toutes les autres (`null` : le schéma partagé).
     *
     * @return list<Finding>
     */
    private function findings(DatasetSession $session, ?FaultySchema $schema): array
    {
        $introspector = self::introspector($session);
        $definitions = [];
        foreach ($introspector->listTables() as $summary) {
            $planted = str_starts_with($summary->name, DatasetSession::PREFIX);
            if (TableKind::Table !== $summary->kind || $planted !== (null !== $schema) || (null !== $schema && !str_starts_with($summary->name, $schema->base))) {
                continue;
            }
            $definitions[] = $introspector->describeTable($summary->name);
        }
        $names = array_map(static fn (TableDefinition $t): string => $t->name, $definitions);
        $counters = array_values(array_filter($introspector->autoCounters(), static fn ($c): bool => \in_array($c->table, $names, true)));

        return (new SchemaAuditor())->audit($session->engine, $definitions, null, $counters);
    }

    /**
     * Une ligne « règle:table:colonnes » par constat, sans le préfixe jetable, triées ; l'index se lit après un « % ».
     *
     * @param list<Finding> $findings
     *
     * @return list<string>
     */
    private function summary(?FaultySchema $schema, array $findings): array
    {
        $lines = [];
        foreach ($findings as $f) {
            $table = null === $schema ? $f->table : preg_replace('/^tdb_ds_[0-9a-f]{8}_/', '', $f->table);
            $table = \is_string($table) ? $table : $f->table;
            $lines[] = $f->rule->value.':'.$table.':'.('redundant_index' === $f->rule->value ? '%'.preg_replace('/^tdb_ds_[0-9a-f]{8}_/', '', (string) $f->index) : implode(',', $f->columns));
        }
        sort($lines);

        return $lines;
    }
}
