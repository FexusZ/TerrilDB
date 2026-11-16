<?php

declare(strict_types=1);

namespace App\Tests\Integration\Driver;

use App\Core\Connection\Engine;
use App\Core\Driver\Exception\TargetConnectionFailedException;
use App\Core\Driver\TargetConnection;
use App\Core\Schema\Change\Algorithm\AlterAlgorithm;
use App\Core\Schema\Change\Algorithm\AlterAlgorithmAdvisor;
use App\Core\Schema\Change\ColumnDraft;
use App\Core\Schema\Change\DdlStatement;
use App\Core\Schema\Change\ForeignKeyDraft;
use App\Core\Schema\Change\IndexDraft;
use App\Core\Schema\Change\SchemaChangePlanner;
use App\Core\Schema\Change\TableDraft;
use App\Core\Schema\ColumnDefinition;
use App\Core\Schema\ForeignKeyDefinition;
use App\Core\Schema\IndexDefinition;
use App\Core\Schema\ReferentialAction;
use App\Core\Schema\TableDefinition;
use App\Driver\DbalConnector;
use App\Driver\MySql\MySqlDriver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * LA vérification de la table de règles d'algorithme contre les vrais MySQL 8.4 / 26 et MariaDB 11.8 / 13. Pour chaque geste, sur
 * une table jetable `tdb_alg_<hex>` créée ici (jamais le schéma partagé, supprimée en `finally`), l'instruction que le vrai
 * planificateur écrit est rejouée avec `ALGORITHM=INSTANT`, puis `INPLACE`, puis `COPY` (et `LOCK=NONE`) : une instruction refusée
 * par le moteur (erreur 1845/1846) prouve « pas cet algorithme ». Le verdict du conseiller ne doit JAMAIS contredire ce que le
 * moteur accepte, et ce qu'on laisse « inconnu » est écrit en toutes lettres. En production, rien de tout cela n'est essayé.
 */
#[Group('integration')]
final class AlterAlgorithmTest extends TestCase
{
    private const array REFUSED = [1845, 1846];

    private string $table = '';
    private string $parent = '';
    private string $child = '';
    private ?\PDO $pdo = null;
    private ?TargetConnection $target = null;

    protected function setUp(): void
    {
        $hex = bin2hex(random_bytes(4));
        $this->table = 'tdb_alg_'.$hex;
        $this->parent = 'tdb_alg_p_'.$hex;
        $this->child = 'tdb_alg_c_'.$hex;
    }

    protected function tearDown(): void
    {
        if (null !== $this->pdo) {
            $this->dropAll();
        }
        $this->target?->close();
        $this->pdo = null;
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function targets(): iterable
    {
        yield 'MySQL' => ['TARGET_MYSQL_URL'];
        yield 'MySQL 26' => ['TARGET_MYSQL26_URL'];
        yield 'MariaDB' => ['TARGET_MARIADB_URL'];
        yield 'MariaDB 13' => ['TARGET_MARIADB13_URL'];
    }

    /**
     * Chaque scénario : le gabarit de table, le code de l'instruction jugée, le changement de brouillon, et le verdict attendu pour
     * MySQL puis MariaDB (`unknown` = écarté exprès : on n'affirme rien).
     *
     * @return iterable<string, array{string, string, string, \Closure(TableDraft): TableDraft, AlterAlgorithm, AlterAlgorithm}>
     */
    public static function scenarios(): iterable
    {
        $I = AlterAlgorithm::Instant;
        $P = AlterAlgorithm::Inplace;
        $C = AlterAlgorithm::Copy;
        $U = AlterAlgorithm::Unknown;

        foreach (['tinyint', 'smallint', 'mediumint', 'bigint', 'decimal(10,2)', 'float', 'double', 'char(5)', 'varchar(10)', 'binary(4)', 'varbinary(8)', 'tinytext', 'text', 'mediumtext', 'longtext', 'tinyblob', 'blob', 'mediumblob', 'longblob', 'date', 'time', 'datetime', 'timestamp', 'year', 'json', "enum('x','y')", 'int'] as $type) {
            yield 'add column '.$type => ['base', 'add_column', 'add_column', static fn (TableDraft $d): TableDraft => self::add($d, 'x', $type, true, null), $I, $I];
        }
        yield 'add NOT NULL column with default' => ['base', 'add_column', 'add_column', static fn (TableDraft $d): TableDraft => self::add($d, 'x', 'int', false, '0'), $I, $I];
        yield 'add NOT NULL column without default' => ['base', 'add_column', 'add_column', static fn (TableDraft $d): TableDraft => self::add($d, 'x', 'int', false, null), $I, $I];
        yield 'add column with text default' => ['base', 'add_column', 'add_column', static fn (TableDraft $d): TableDraft => self::add($d, 'x', 'varchar(10)', true, "'a'"), $I, $I];
        yield 'add column default now()' => ['base', 'add_column', 'add_column', static fn (TableDraft $d): TableDraft => self::add($d, 'x', 'timestamp', true, 'now()'), $I, $I];
        yield 'add column default uuid() is not claimed' => ['base', 'add_column', 'add_column', static fn (TableDraft $d): TableDraft => self::add($d, 'x', 'varchar(36)', true, 'uuid()'), $U, $U];
        yield 'add column default current_date is not claimed' => ['base', 'add_column', 'add_column', static fn (TableDraft $d): TableDraft => self::add($d, 'x', 'date', true, 'current_date'), $U, $U];
        yield 'add auto increment column is not claimed' => ['base', 'add_column', 'add_column', static fn (TableDraft $d): TableDraft => self::add($d, 'x', 'int', false, null, true), $U, $U];
        yield 'drop an unindexed column' => ['base', 'drop_column', 'drop_column', static fn (TableDraft $d): TableDraft => self::dropColumn($d, 'c'), $I, $I];
        yield 'drop a column once its index is rebuilt without it' => ['base', 'drop_column', 'drop_column', static fn (TableDraft $d): TableDraft => self::dropColumn($d, 'm'), $I, $I];
        yield 'rename an unindexed column' => ['base', 'rename_column', 'rename_column', static fn (TableDraft $d): TableDraft => self::retype($d, 'c', '=', newName: 'c2'), $I, $I];
        yield 'rename an indexed column' => ['base', 'rename_column', 'rename_column', static fn (TableDraft $d): TableDraft => self::retype($d, 'b', '=', newName: 'b2'), $U, $I];
        yield 'rename a foreign key column' => ['base', 'rename_column', 'rename_column', static fn (TableDraft $d): TableDraft => self::retype($d, 'd', '=', newName: 'd2'), $U, $I];
        yield 'rename a primary key column that another table references' => ['base', 'rename_column', 'rename_column', static fn (TableDraft $d): TableDraft => self::retype($d, 'id', '=', newName: 'id2'), $U, $I];
        foreach ([['n', 'bigint'], ['n', 'varchar(20)'], ['n', 'int unsigned'], ['bg', 'int'], ['dc', 'decimal(12,2)'], ['dc', 'decimal(10,4)'], ['c', 'text'], ['dt', 'timestamp'], ['ch', 'varchar(10)'], ['a', 'varchar(10)'], ['e', 'longtext'], ['en', "enum('z','a','b')"], ['en', "enum('a')"]] as [$column, $type]) {
            yield 'change the type of '.$column.' to '.$type => ['base', 'modify_column', 'modify_column', static fn (TableDraft $d): TableDraft => self::retype($d, $column, $type), $C, $C];
        }
        yield 'append enum members' => ['base', 'modify_column', 'modify_column', static fn (TableDraft $d): TableDraft => self::retype($d, 'en', "enum('a','b','c')"), $I, $I];
        yield 'grow a varchar is not claimed' => ['base', 'modify_column', 'modify_column', static fn (TableDraft $d): TableDraft => self::retype($d, 'c', 'varchar(30)'), $U, $U];
        yield 'grow a varchar across the length-byte class is not claimed' => ['base', 'modify_column', 'modify_column', static fn (TableDraft $d): TableDraft => self::retype($d, 'c', 'varchar(300)'), $U, $U];
        yield 'change a default' => ['base', 'modify_column', 'modify_column', static fn (TableDraft $d): TableDraft => self::retype($d, 'n', '=', default: '5', keepDefault: false), $I, $I];
        yield 'change a default on a NOT NULL column' => ['base', 'modify_column', 'modify_column', static fn (TableDraft $d): TableDraft => self::retype($d, 'a', '=', default: "'x'", keepDefault: false), $I, $I];
        yield 'default now() on a datetime' => ['base', 'modify_column', 'modify_column', static fn (TableDraft $d): TableDraft => self::retype($d, 'dt', '=', default: 'now()', keepDefault: false), $I, $I];
        yield 'drop a default' => ['base', 'modify_column', 'modify_column', static fn (TableDraft $d): TableDraft => self::retype($d, 'd', '=', default: null, keepDefault: false), $I, $I];
        yield 'drop NOT NULL' => ['base', 'modify_column', 'modify_column', static fn (TableDraft $d): TableDraft => self::retype($d, 'a', '=', nullable: true), $P, $P];
        yield 'set NOT NULL' => ['base', 'modify_column', 'modify_column', static fn (TableDraft $d): TableDraft => self::retype($d, 'c', '=', nullable: false), $U, $P];
        yield 'set NOT NULL on an indexed column' => ['base', 'modify_column', 'modify_column', static fn (TableDraft $d): TableDraft => self::retype($d, 'b', '=', nullable: false), $U, $P];
        yield 'set NOT NULL and a default' => ['base', 'modify_column', 'modify_column', static fn (TableDraft $d): TableDraft => self::retype($d, 'n', '=', nullable: false, default: '4', keepDefault: false), $U, $P];
        yield 'set NOT NULL and change the type' => ['base', 'modify_column', 'modify_column', static fn (TableDraft $d): TableDraft => self::retype($d, 'n', 'bigint', nullable: false, default: '4', keepDefault: false), $C, $C];
        yield 'add an index' => ['base', 'add_index', 'add_index', static fn (TableDraft $d): TableDraft => self::index($d, new IndexDraft(null, 'k_c', ['c'], false, false, null, null)), $P, $P];
        yield 'add a unique index' => ['base', 'add_index', 'add_index', static fn (TableDraft $d): TableDraft => self::index($d, new IndexDraft(null, 'u_c', ['c'], true, false, null, null)), $P, $P];
        yield 'add a composite index' => ['base', 'add_index', 'add_index', static fn (TableDraft $d): TableDraft => self::index($d, new IndexDraft(null, 'k_cn', ['c', 'n'], false, false, null, null)), $P, $P];
        yield 'drop an index' => ['base', 'drop_index', 'drop_index', static fn (TableDraft $d): TableDraft => self::withoutIndex($d, 'k_b'), $P, $P];
        yield 'rename an index' => ['base', 'rename_index', 'rename_index', static fn (TableDraft $d): TableDraft => self::renameIndex($d, 'k_b', 'k_b2'), $P, $I];
        yield 'add a foreign key' => ['base', 'add_foreign_key', 'add_foreign_key', static fn (TableDraft $d): TableDraft => self::foreignKey($d, new ForeignKeyDraft(null, 'fk_n', ['n'], 'PARENT', ['id'], ReferentialAction::Restrict, ReferentialAction::Restrict)), $C, $C];
        yield 'drop a foreign key' => ['base', 'drop_foreign_key', 'drop_foreign_key', static fn (TableDraft $d): TableDraft => self::foreignKey($d, null), $P, $I];
        yield 'add a primary key' => ['nopk', 'add_primary_key', 'add_primary_key', static fn (TableDraft $d): TableDraft => self::index($d, new IndexDraft(null, 'PRIMARY', ['id'], true, true, null, null)), $P, $P];
        yield 'add a composite primary key' => ['nopk', 'add_primary_key', 'add_primary_key', static fn (TableDraft $d): TableDraft => self::index($d, new IndexDraft(null, 'PRIMARY', ['id', 'x'], true, true, null, null)), $P, $P];
        yield 'drop a primary key' => ['pk2', 'drop_primary_key', 'drop_primary_key', static fn (TableDraft $d): TableDraft => self::withoutIndex($d, 'PRIMARY'), $C, $C];
        yield 'move a primary key: drop' => ['pk2', 'drop_primary_key', 'drop_primary_key', static fn (TableDraft $d): TableDraft => self::movePrimary($d), $C, $C];
        yield 'move a primary key: add' => ['pk2', 'add_primary_key', 'add_primary_key', static fn (TableDraft $d): TableDraft => self::movePrimary($d), $P, $P];
        yield 'rename the table' => ['pk2', 'rename_table', 'rename_table', static fn (TableDraft $d): TableDraft => new TableDraft($d->name.'r', $d->columns, $d->indexes, $d->foreignKeys), $I, $I];
    }

    /**
     * @param \Closure(TableDraft): TableDraft $change
     */
    #[DataProvider('scenarioMatrix')]
    public function testTheAdvisorNeverContradictsTheEngine(string $env, string $fixture, string $operation, \Closure $change, AlterAlgorithm $expected): void
    {
        $engine = TargetDatabase::engine($env);
        $this->open($env);
        $this->build($fixture);
        $current = $this->describe($this->table);
        $draft = $change($this->draftOf($current));
        $draft = $this->named($draft);
        $plan = (new SchemaChangePlanner())->plan($engine, $current, $draft);
        $advice = (new AlterAlgorithmAdvisor())->advise($engine, $this->target?->version(), $current, $draft, $plan);

        $index = null;
        foreach ($plan->statements as $position => $statement) {
            if ($statement->operation === $operation) {
                $index = $position;
                break;
            }
        }
        self::assertNotNull($index, 'the plan should contain a '.$operation.' statement: '.implode(' | ', array_map(static fn (DdlStatement $s): string => $s->sql, $plan->statements)));
        $verdict = $advice[$index];
        self::assertSame($expected, $verdict->algorithm, $plan->statements[$index]->sql.' on '.$env.' (reason: '.($verdict->reason ?? 'none').')');
        if (AlterAlgorithm::Unknown === $expected) {
            self::assertNull($verdict->blocksWrites);
            self::assertNull($verdict->copiesTable);

            return;
        }

        $observed = [];
        foreach (['INSTANT' => 'ALGORITHM=INSTANT', 'INPLACE' => 'ALGORITHM=INPLACE', 'INPLACE_NONE' => 'ALGORITHM=INPLACE, LOCK=NONE', 'COPY' => 'ALGORITHM=COPY', 'COPY_NONE' => 'ALGORITHM=COPY, LOCK=NONE'] as $name => $clause) {
            $this->build($fixture);
            foreach (\array_slice($plan->statements, 0, $index) as $before) {
                $this->pdo()->exec($before->sql);
            }
            $observed[$name] = $this->tryWith($plan->statements[$index], $clause);
            $this->dropAll();
        }
        $why = $plan->statements[$index]->sql.' on '.$env.' observed '.json_encode($observed);

        match ($expected) {
            AlterAlgorithm::Instant => self::assertSame('ok', $observed['INSTANT'], 'instant claimed: '.$why),
            AlterAlgorithm::Inplace => self::assertSame(['refused', 'ok'], [$observed['INSTANT'], $observed['INPLACE']], 'inplace claimed: '.$why),
            AlterAlgorithm::Copy => self::assertSame(['refused', 'refused', 'ok'], [$observed['INSTANT'], $observed['INPLACE'], $observed['COPY']], 'copy claimed: '.$why),
            default => self::fail('A verified verdict is instant, inplace or copy.'),
        };
        if (AlterAlgorithm::Instant === $expected) {
            // INSTANT n'accepte aucune clause LOCK (MySQL : 1221) : il ne touche pas aux données, seul un verrou de métadonnées bref.
            self::assertFalse($verdict->blocksWrites);
            self::assertFalse($verdict->copiesTable);
        } elseif (AlterAlgorithm::Inplace === $expected) {
            self::assertFalse($verdict->blocksWrites);
            self::assertSame('ok', $observed['INPLACE_NONE'], 'LOCK=NONE accepted, so writes are not blocked: '.$why);
            self::assertNull($verdict->copiesTable, 'an in-place change may rebuild the table: not claimed');
        } else {
            self::assertSame('refused' === $observed['COPY_NONE'] ? true : null, $verdict->blocksWrites, 'COPY refuses LOCK=NONE on MySQL (blocks writes); MariaDB accepts it, so nothing is claimed: '.$why);
            self::assertTrue($verdict->copiesTable);
            self::assertGreaterThan(0, $verdict->estimatedExtraBytes ?? 0, 'the size of the table is known');
        }
    }

    /**
     * @return iterable<string, array{string, string, string, \Closure(TableDraft): TableDraft, AlterAlgorithm}>
     */
    public static function scenarioMatrix(): iterable
    {
        foreach (self::targets() as $targetName => [$env]) {
            $mariaDb = Engine::MariaDb === TargetDatabase::engine($env);
            foreach (self::scenarios() as $name => [$fixture, , $operation, $change, $mysql, $maria]) {
                yield $targetName.': '.$name => [$env, $fixture, $operation, $change, $mariaDb ? $maria : $mysql];
            }
        }
    }

    #[DataProvider('targets')]
    public function testSettingNotNullInPlaceDependsOnTheSqlModeOnMySqlOnly(string $env): void
    {
        $this->open($env);
        $mariaDb = Engine::MariaDb === TargetDatabase::engine($env);
        $results = [];
        foreach (['STRICT_ALL_TABLES', ''] as $mode) {
            $this->build('base');
            $this->pdo()->exec("SET SESSION sql_mode = '".$mode."'");
            $results[$mode] = $this->tryWith(new DdlStatement(\sprintf('ALTER TABLE `%s` MODIFY COLUMN `c` varchar(20) NOT NULL', $this->table), 'modify_column', 'c', false), 'ALGORITHM=INPLACE, LOCK=NONE');
        }

        self::assertSame('ok', $results['STRICT_ALL_TABLES']);
        self::assertSame($mariaDb ? 'ok' : 'refused', $results[''], 'MySQL refuses the in-place NOT NULL outside strict mode, so the rule says "depends on sql_mode"');
    }

    #[DataProvider('targets')]
    public function testOnlyMySqlRefusesLockNoneWithCopy(string $env): void
    {
        $this->open($env);
        $this->build('base');
        $statement = new DdlStatement(\sprintf('ALTER TABLE `%s` MODIFY COLUMN `n` bigint NULL', $this->table), 'modify_column', 'n', false);

        self::assertSame(Engine::MariaDb === TargetDatabase::engine($env) ? 'ok' : 'refused', $this->tryWith($statement, 'ALGORITHM=COPY, LOCK=NONE'), 'why the copy of MariaDB is not claimed to block writes');
    }

    #[DataProvider('targets')]
    public function testThePreviewOfARealTableCarriesTheCostOfACopy(string $env): void
    {
        $engine = TargetDatabase::engine($env);
        $this->open($env);
        $this->build('base');
        $current = $this->describe($this->table);
        $draft = self::retype($this->draftOf($current), 'n', 'bigint');
        $plan = (new SchemaChangePlanner())->plan($engine, $current, $draft);
        $advice = (new AlterAlgorithmAdvisor())->advise($engine, $this->target?->version(), $current, $draft, $plan);

        self::assertSame('InnoDB', $current->storageEngine);
        self::assertSame(AlterAlgorithm::Copy, $advice[0]->algorithm);
        self::assertSame(($current->dataBytes ?? 0) + ($current->indexBytes ?? 0), $advice[0]->estimatedExtraBytes);
        self::assertGreaterThan(0, $advice[0]->estimatedExtraBytes);
    }

    private function open(string $env): void
    {
        try {
            $this->target = (new MySqlDriver(new DbalConnector()))->connect(TargetDatabase::engine($env), TargetDatabase::parameters($env));
        } catch (TargetConnectionFailedException) {
            self::markTestSkipped($env.' is unreachable (docker compose --profile targets up -d --wait).');
        }
        $this->pdo = LockPeer::connect($env);
    }

    private function pdo(): \PDO
    {
        return $this->pdo ?? throw new \LogicException('Not opened.');
    }

    private function describe(string $table): TableDefinition
    {
        return ($this->target ?? throw new \LogicException('Not opened.'))->introspector()->describeTable($table);
    }

    private function dropAll(): void
    {
        $pdo = $this->pdo();
        foreach ([$this->child, $this->table, $this->table.'r', $this->parent] as $name) {
            $pdo->exec('DROP TABLE IF EXISTS `'.$name.'`');
        }
    }

    /**
     * Les tables d'essai, toujours à neuf. `base` : une table riche avec une clé étrangère vers `parent` et une table enfant qui la
     * référence ; `nopk` et `pk2` : sans clé primaire, et avec une clé primaire sans clé auto-incrémentée.
     */
    private function build(string $fixture): void
    {
        $pdo = $this->pdo();
        $this->dropAll();
        $t = '`'.$this->table.'`';
        $p = '`'.$this->parent.'`';
        $pdo->exec('CREATE TABLE '.$p.' (id int NOT NULL PRIMARY KEY) ENGINE=InnoDB');
        for ($i = 0; $i < 30; ++$i) {
            $pdo->exec('INSERT INTO '.$p.' VALUES ('.$i.')');
        }
        if ('nopk' === $fixture) {
            $pdo->exec('CREATE TABLE '.$t.' (id int NOT NULL, x int NOT NULL) ENGINE=InnoDB');
        } elseif ('pk2' === $fixture) {
            $pdo->exec('CREATE TABLE '.$t.' (id int NOT NULL, x int NOT NULL, PRIMARY KEY (id)) ENGINE=InnoDB');
        } else {
            $pdo->exec('CREATE TABLE '.$t.' (id int NOT NULL AUTO_INCREMENT, a varchar(50) NOT NULL, b int NULL, c varchar(20) NULL, d int NULL DEFAULT 1, e text NULL, en enum(\'a\',\'b\') NULL, n int NULL, m varchar(20) NULL, bg bigint NULL, dc decimal(10,2) NULL, dt datetime NULL, ch char(5) NULL, PRIMARY KEY (id), KEY k_b (b), KEY k_m_n (m, n), KEY k_d (d), CONSTRAINT fk0_'.substr($this->table, -8).' FOREIGN KEY (d) REFERENCES '.$p.' (id)) ENGINE=InnoDB');
            $pdo->exec('CREATE TABLE `'.$this->child.'` (id int NOT NULL PRIMARY KEY, t int NOT NULL, KEY k_t (t), CONSTRAINT fkc_'.substr($this->table, -8).' FOREIGN KEY (t) REFERENCES '.$t.' (id)) ENGINE=InnoDB');
        }
        for ($i = 1; $i <= 20; ++$i) {
            $pdo->exec('base' === $fixture
                ? \sprintf("INSERT INTO %s (a, b, c, d, n, m, bg, dc, dt, ch) VALUES ('r%d', %d, 'c%d', 1, %d, 'm%d', %d, 1.5, now(), 'ch')", $t, $i, $i, $i, $i, $i, $i)
                : \sprintf('INSERT INTO %s (id, x) VALUES (%d, %d)', $t, $i, $i));
        }
    }

    /**
     * Rejoue l'instruction avec la clause demandée : `ok` si le moteur l'accepte, `refused` s'il refuse l'algorithme (1845/1846),
     * et tout autre échec est une erreur du test lui-même.
     */
    private function tryWith(DdlStatement $statement, string $clause): string
    {
        $sql = $statement->sql;
        if (str_starts_with($sql, 'RENAME TABLE')) {
            // RENAME TABLE n'accepte pas de clause ALGORITHM : son équivalent ALTER ... RENAME TO est ce que le moteur exécute.
            $sql = \sprintf('ALTER TABLE `%s` RENAME TO `%s`', $this->table, $this->table.'r');
        }
        if (1 === preg_match('/^DROP INDEX (`[^`]+`) ON (`[^`]+`)$/', $sql, $m)) {
            // MariaDB n'accepte pas de clause ALGORITHM derrière DROP INDEX ; c'est le même ALTER TABLE ... DROP INDEX pour le moteur.
            $sql = \sprintf('ALTER TABLE %s DROP INDEX %s', $m[2], $m[1]);
        }
        $sql .= str_starts_with($sql, 'ALTER TABLE') ? ', '.$clause : ' '.str_replace(', ', ' ', $clause);
        try {
            $this->pdo()->exec($sql);

            return 'ok';
        } catch (\PDOException $e) {
            $info = $e->errorInfo;
            $code = \is_array($info) && \is_int($info[1] ?? null) ? $info[1] : 0;
            if (\in_array($code, self::REFUSED, true)) {
                return 'refused';
            }

            self::fail('The engine failed for another reason than the algorithm ('.$code.'): '.$sql.' -> '.$e->getMessage());
        }
    }

    private function named(TableDraft $draft): TableDraft
    {
        // Un nom de contrainte est unique dans toute la base (MariaDB) : on le suffixe pour ne jamais heurter une autre session.
        $suffix = substr($this->table, -8);
        $foreignKeys = array_map(fn (ForeignKeyDraft $f): ForeignKeyDraft => 'PARENT' === $f->referencedTable ? new ForeignKeyDraft($f->originalName, $f->name.$suffix, $f->columns, $this->parent, $f->referencedColumns, $f->onUpdate, $f->onDelete) : $f, $draft->foreignKeys);
        $name = str_ends_with($draft->name, 'r') ? $this->table.'r' : $this->table;

        return new TableDraft($name, $draft->columns, $draft->indexes, $foreignKeys);
    }

    private function draftOf(TableDefinition $table): TableDraft
    {
        return new TableDraft(
            $table->name,
            array_map(static fn (ColumnDefinition $c): ColumnDraft => new ColumnDraft($c->name, $c->name, $c->type, $c->nullable, $c->default, $c->autoIncrement), $table->columns),
            array_map(static fn (IndexDefinition $i): IndexDraft => new IndexDraft($i->name, $i->name, $i->columns, $i->unique, $i->primary, null, null), $table->indexes),
            array_map(static fn (ForeignKeyDefinition $f): ForeignKeyDraft => new ForeignKeyDraft($f->name, $f->name, $f->columns, $f->referencedTable, $f->referencedColumns, $f->onUpdate, $f->onDelete), $table->foreignKeys),
        );
    }

    private static function add(TableDraft $d, string $name, string $type, bool $nullable, ?string $default, bool $auto = false): TableDraft
    {
        return new TableDraft($d->name, [...$d->columns, new ColumnDraft(null, $name, $type, $nullable, $default, $auto)], $d->indexes, $d->foreignKeys);
    }

    private static function dropColumn(TableDraft $d, string $name): TableDraft
    {
        $columns = array_values(array_filter($d->columns, static fn (ColumnDraft $c): bool => $c->name !== $name));
        $indexes = [];
        foreach ($d->indexes as $i) {
            $kept = array_values(array_filter($i->columns, static fn (string $c): bool => $c !== $name));
            if ([] !== $kept) {
                $indexes[] = new IndexDraft($i->originalName, $i->name, $kept, $i->unique, $i->primary, null, null);
            }
        }

        return new TableDraft($d->name, $columns, $indexes, $d->foreignKeys);
    }

    private static function retype(TableDraft $d, string $column, string $type, ?bool $nullable = null, ?string $default = null, bool $keepDefault = true, ?string $newName = null, bool $auto = false): TableDraft
    {
        $columns = array_map(static fn (ColumnDraft $c): ColumnDraft => $c->name === $column
            ? new ColumnDraft($c->originalName, $newName ?? $c->name, '=' === $type ? $c->type : $type, $nullable ?? $c->nullable, $keepDefault ? $c->default : $default, $auto || $c->autoIncrement)
            : $c, $d->columns);

        return new TableDraft(
            $d->name,
            $columns,
            array_map(static fn (IndexDraft $i): IndexDraft => new IndexDraft($i->originalName, $i->name, self::follow($i->columns, $column, $newName), $i->unique, $i->primary, null, null), $d->indexes),
            array_map(static fn (ForeignKeyDraft $f): ForeignKeyDraft => new ForeignKeyDraft($f->originalName, $f->name, self::follow($f->columns, $column, $newName), $f->referencedTable, $f->referencedColumns, $f->onUpdate, $f->onDelete), $d->foreignKeys),
        );
    }

    /**
     * @param list<string> $names
     *
     * @return list<string>
     */
    private static function follow(array $names, string $from, ?string $to): array
    {
        return array_map(static fn (string $n): string => $n === $from ? ($to ?? $n) : $n, $names);
    }

    private static function index(TableDraft $d, IndexDraft $new): TableDraft
    {
        return new TableDraft($d->name, $d->columns, [...$d->indexes, $new], $d->foreignKeys);
    }

    private static function withoutIndex(TableDraft $d, string $name): TableDraft
    {
        return new TableDraft($d->name, $d->columns, array_values(array_filter($d->indexes, static fn (IndexDraft $i): bool => $i->name !== $name)), $d->foreignKeys);
    }

    private static function renameIndex(TableDraft $d, string $from, string $to): TableDraft
    {
        return new TableDraft($d->name, $d->columns, array_map(static fn (IndexDraft $i): IndexDraft => $i->name === $from ? new IndexDraft($i->originalName, $to, $i->columns, $i->unique, $i->primary, null, null) : $i, $d->indexes), $d->foreignKeys);
    }

    private static function foreignKey(TableDraft $d, ?ForeignKeyDraft $add): TableDraft
    {
        return new TableDraft($d->name, $d->columns, $d->indexes, null === $add ? [] : [...$d->foreignKeys, $add]);
    }

    private static function movePrimary(TableDraft $d): TableDraft
    {
        return new TableDraft($d->name, $d->columns, array_map(static fn (IndexDraft $i): IndexDraft => $i->primary ? new IndexDraft($i->originalName, $i->name, ['x'], true, true, null, null) : $i, $d->indexes), $d->foreignKeys);
    }
}
