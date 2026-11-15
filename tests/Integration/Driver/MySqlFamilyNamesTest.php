<?php

declare(strict_types=1);

namespace App\Tests\Integration\Driver;

use App\Core\Export\CsvExporter;
use App\Core\Export\JsonExporter;
use App\Core\Schema\Change\ColumnDraft;
use App\Core\Schema\Change\Exception\SchemaChangeFailedException;
use App\Core\Schema\Change\SchemaChangePlanner;
use App\Core\Schema\Change\TableDraft;
use App\Core\Schema\Exception\TableNotFoundException;
use App\Core\Schema\TablePageRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Issue #86, point 11 : noms de bases et de tables exotiques (tirets, guillemets inversés, unicode, espaces, émoji, #mysql50#,
 * casse) dans une base jetable, contre les quatre cibles.
 */
#[Group('integration')]
final class MySqlFamilyNamesTest extends TestCase
{
    use MySqlFamilyTarget;

    /** Noms qui éprouvent le tri à l'octet : casse (T/t), tiret, espace, backtick, unicode (BMP), préfixe hérité. */
    private const TABLES = ['Tx', 'tx', 'TX', 't-x', 't x', 'tx_', 'ty`z', 'été', 'Été', "t\u{8868}€", '#mysql50#old', 'a.b', '0', 'T'];

    private function database(): string
    {
        $name = 'tdb-ex`é '.bin2hex(random_bytes(3));
        $this->drops[] = 'DROP DATABASE IF EXISTS '.self::q($name);
        $this->raw('CREATE DATABASE '.self::q($name));

        return $name;
    }

    /**
     * @return list<string> les noms que le moteur a vraiment accepté de créer
     */
    private function createTables(string $database): array
    {
        $created = [];
        foreach (self::TABLES as $table) {
            try {
                $this->raw('CREATE TABLE '.self::q($database).'.'.self::q($table).' (id int NOT NULL PRIMARY KEY, `col-umn` varchar(20) NULL, `c``q` int NULL, `é` int NULL)');
                $created[] = $table;
            } catch (\Throwable) {
            }
        }

        return $created;
    }

    #[DataProvider('targets')]
    public function testEveryExoticNameIsListedPagedAndDescribed(string $env): void
    {
        $this->open($env);
        $database = $this->database();
        $created = $this->createTables($database);
        self::assertContains('t-x', $created);
        self::assertContains("t\u{8868}€", $created);
        self::assertContains('ty`z', $created);
        self::assertContains('Tx', $created);
        self::assertContains('tx', $created);

        $inside = $this->openOn($database);
        $expected = $created;
        usort($expected, strcmp(...));

        $listed = array_map(static fn ($t): string => $t->name, $inside->introspector()->listTables());
        self::assertSame($expected, $listed, 'listTables : tri à l\'octet, tous les noms');

        foreach ([1, 2, 3, 7] as $limit) {
            $paged = [];
            $cursor = null;
            $guard = 0;
            do {
                $page = $inside->introspector()->listTablesPage(new TablePageRequest($limit, $cursor));
                foreach ($page->tables as $t) {
                    $paged[] = $t->name;
                }
                $cursor = $page->nextCursor;
            } while (null !== $cursor && ++$guard < 100);
            self::assertSame($expected, $paged, 'pagination par BINARY TABLE_NAME, limite '.$limit);
        }

        foreach ($created as $name) {
            $table = $inside->introspector()->describeTable($name);
            self::assertSame($name, $table->name);
            self::assertSame(['id', 'col-umn', 'c`q', 'é'], array_map(static fn ($c): string => $c->name, $table->columns), $name);
        }
        self::assertSame(['Tx', 'tx'], array_values(array_intersect($listed, ['Tx', 'tx'])), 'Tx et tx restent deux tables distinctes (lower_case_table_names = 0)');
    }

    /**
     * Mesuré : les noms d'objets sont stockés en utf8mb3. MariaDB refuse un émoji (4 octets) ; MySQL l'ACCEPTE et le range en `?`
     * (la table n'est plus adressable par son nom). Ce n'est pas un défaut de TerrilDB, mais la liste ne doit pas échouer.
     */
    #[DataProvider('targets')]
    public function testAFourByteNameIsRefusedOrDegradedByTheEngineButNeverBreaksTheListing(string $env): void
    {
        $this->open($env);
        $database = $this->database();
        $this->raw('CREATE TABLE '.self::q($database).'.`ok` (id int)');
        $emoji = "t\u{1F600}";
        $accepted = true;
        try {
            $this->raw('CREATE TABLE '.self::q($database).'.'.self::q($emoji).' (id int)');
        } catch (\Throwable) {
            $accepted = false;
        }
        self::assertSame(!$this->mariaDb(), $accepted);

        $listed = array_map(static fn ($t): string => $t->name, $this->openOn($database)->introspector()->listTables());
        self::assertSame($accepted ? ['ok', 't?'] : ['ok'], $listed);

        // Demander la table par le nom qu'on a écrit : le moteur la retrouve (ou non), jamais une erreur de collation brute.
        try {
            $this->openOn($database)->introspector()->describeTable($emoji);
        } catch (TableNotFoundException) {
        }
        self::assertSame(['id'], array_map(static fn ($c): string => $c->name, $this->openOn($database)->introspector()->describeTable($accepted ? 't?' : 'ok')->columns));

        // Chercher par un émoji ne trouve rien et ne casse pas : ni erreur de collation, ni tout rendre.
        $search = $this->openOn($database)->introspector()->listTablesPage(new TablePageRequest(100, null, "\u{1F600}"));
        self::assertSame([], $search->tables);
    }

    /** Une base au nom de l'ancien préfixe : MySQL l'accepte et on y entre ; MariaDB la refuse à la création (1102), donc rien à lire. */
    #[DataProvider('targets')]
    public function testADatabaseNamedLikeALegacyOneCanBeOpened(string $env): void
    {
        $this->open($env);
        $name = '#mysql50#legacy-'.bin2hex(random_bytes(3));
        $this->drops[] = 'DROP DATABASE IF EXISTS '.self::q($name);
        if ($this->mariaDb()) {
            $this->expectException(SchemaChangeFailedException::class);
            $this->expectExceptionMessage('Incorrect database name');
        }
        $this->raw('CREATE DATABASE '.self::q($name));
        $this->raw('CREATE TABLE '.self::q($name).'.`t-1` (id int NOT NULL PRIMARY KEY)');

        $databases = array_map(static fn ($d): string => $d->name, $this->target?->introspector()->listDatabases() ?? []);
        self::assertContains($name, $databases);

        $inside = $this->openOn($name);
        self::assertSame(['t-1'], array_map(static fn ($t): string => $t->name, $inside->introspector()->listTables()));
        self::assertSame(['id'], array_map(static fn ($c): string => $c->name, $inside->introspector()->describeTable('t-1')->columns));
    }

    #[DataProvider('targets')]
    public function testSearchTreatsExoticCharactersAsText(string $env): void
    {
        $this->open($env);
        $database = $this->database();
        $created = $this->createTables($database);
        $inside = $this->openOn($database);

        $found = static fn (string $q): array => array_map(static fn ($t): string => $t->name, $inside->introspector()->listTablesPage(new TablePageRequest(100, null, $q))->tables);
        self::assertSame(['t-x'], $found('t-'));
        self::assertSame(['ty`z'], $found('`'));
        self::assertSame(['a.b'], $found('.'));
        self::assertSame(['TX', 'Tx', 'tx', 'tx_'], $found('tx'), 'sans casse, par octets');
        self::assertContains('Été', $found('é'), 'la recherche ne se limite pas à l\'ASCII');
        self::assertGreaterThan(0, \count($created));
    }

    #[DataProvider('targets')]
    public function testTheStructureEditorWorksOnExoticNames(string $env): void
    {
        $this->open($env);
        $database = $this->database();
        $created = $this->createTables($database);
        $inside = $this->openOn($database);
        $editor = $this->openOn($database);

        // « #mysql50#old » n'existe que sur MySQL : MariaDB refuse ce nom à la création (1103).
        self::assertSame(!$this->mariaDb(), \in_array('#mysql50#old', $created, true));
        foreach (array_intersect(['t-x', 'ty`z', "t\u{8868}€", '#mysql50#old', 'a.b'], $created) as $name) {
            $before = $inside->introspector()->describeTable($name);
            self::assertSame([], (new SchemaChangePlanner())->plan($this->engine, $before, self::draftOf($before))->statements, $name);

            $columns = array_map(
                static fn ($c): ColumnDraft => new ColumnDraft($c->name, 'col-umn' === $c->name ? 'col`2 é' : $c->name, $c->type, $c->nullable, $c->default, $c->autoIncrement),
                $before->columns,
            );
            $draft = new TableDraft($name, [...$columns, new ColumnDraft(null, 'new-col', 'int', true, null, false)], self::draftOf($before)->indexes, []);
            $plan = (new SchemaChangePlanner())->plan($this->engine, $before, $draft);
            self::assertNotSame([], $plan->statements);
            $editor->ddl()->execute($plan->statements, $plan->transactional);

            $after = $inside->introspector()->describeTable($name);
            self::assertSame(['id', 'col`2 é', 'c`q', 'é', 'new-col'], array_map(static fn ($c): string => $c->name, $after->columns), $name);
            self::assertSame([], (new SchemaChangePlanner())->plan($this->engine, $after, self::draftOf($after))->statements, $name);
        }
    }

    #[DataProvider('targets')]
    public function testExportKeepsExoticNamesAndValues(string $env): void
    {
        $this->open($env);
        $database = $this->database();
        $this->createTables($database);
        $name = 'ty`z';
        $this->raw('INSERT INTO '.self::q($database).'.'.self::q($name)." (id, `col-umn`, `c``q`, `é`) VALUES (1, 'a,\"b\"\n😀', 2, 3)");

        $inside = $this->openOn($database);
        $result = $inside->runner()->run('SELECT * FROM '.self::q($name), \App\Core\Guard\StatementKind::Read, new \App\Core\Query\QueryOptions());

        self::assertSame(['id', 'col-umn', 'c`q', 'é'], $result->columns);
        $csv = (new CsvExporter())->export($result);
        self::assertSame("id,col-umn,c`q,é\r\n1,\"a,\"\"b\"\"\n😀\",2,3\r\n", $csv);
        $json = json_decode((new JsonExporter())->export($result), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame([['id' => 1, 'col-umn' => "a,\"b\"\n😀", 'c`q' => 2, 'é' => 3]], $json);
    }
}
