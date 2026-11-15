<?php

declare(strict_types=1);

namespace App\Tests\Integration\Driver;

use App\Core\Schema\Change\ColumnDraft;
use App\Core\Schema\Change\SchemaChangePlanner;
use App\Core\Schema\Change\TableDraft;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Issue #86, points 4 et 5 : largeur d'affichage des types et collations par défaut, contre MySQL 8.4/26 et MariaDB 11.8/13.
 */
#[Group('integration')]
final class MySqlFamilyStructureTest extends TestCase
{
    use MySqlFamilyTarget;

    private const WIDTHS = 'id int(11) NOT NULL PRIMARY KEY, small smallint(5) NULL, big bigint(20) NULL, flag tinyint(1) NULL, zf int(6) unsigned zerofill NULL,
        created timestamp NULL DEFAULT NULL, stamp6 timestamp(6) NULL DEFAULT NULL, d datetime NULL, t time(3) NULL, y year NULL, y4 year(4) NULL, dec1 decimal(8,2) NULL, bit1 bit(1) NULL';

    #[DataProvider('targets')]
    public function testTheFingerprintIsTheSameFromOneReadToTheNext(string $env): void
    {
        $target = $this->open($env);
        $name = $this->table(self::WIDTHS);

        $first = SchemaChangePlanner::fingerprint($this->describe($name));
        $second = SchemaChangePlanner::fingerprint($target->introspector()->describeTable($name));

        self::assertSame($first, $second);
    }

    #[DataProvider('targets')]
    public function testTheDisplayWidthIsReportedAsTheEngineDoesAndLeftAloneBySafeEdits(string $env): void
    {
        $this->open($env);
        $name = $this->table(self::WIDTHS);
        $before = $this->describe($name);

        // Mesuré : MySQL 8.0.19+ ne rend plus la largeur (int, bigint, smallint), sauf tinyint(1) et zerofill ; MariaDB la rend.
        $types = [];
        foreach ($before->columns as $c) {
            $types[$c->name] = $c->type;
        }
        self::assertSame($this->mariaDb() ? 'int(11)' : 'int', $types['id']);
        self::assertSame($this->mariaDb() ? 'smallint(5)' : 'smallint', $types['small']);
        self::assertSame($this->mariaDb() ? 'bigint(20)' : 'bigint', $types['big']);
        self::assertSame('tinyint(1)', $types['flag']);
        self::assertSame('timestamp(6)', $types['stamp6']);
        self::assertSame('time(3)', $types['t']);

        // Aperçu du même brouillon : rien à faire.
        $plan = (new SchemaChangePlanner())->plan($this->engine, $before, self::draftOf($before));
        self::assertSame([], $plan->statements);

        // Aller-retour de l'éditeur : une colonne change de nullabilité, les autres sont redites telles que lues.
        $flipped = array_map(
            static fn ($c): ColumnDraft => new ColumnDraft($c->name, $c->name, $c->type, 'created' === $c->name ? false : $c->nullable, $c->default, $c->autoIncrement),
            $before->columns,
        );
        $draft = new TableDraft($name, $flipped, self::draftOf($before)->indexes, []);
        $plan = (new SchemaChangePlanner())->plan($this->engine, $before, $draft);
        self::assertNotSame([], $plan->statements);
        $this->target?->ddl()->execute($plan->statements, $plan->transactional);

        $after = $this->describe($name);
        foreach ($before->columns as $c) {
            $a = $after->column($c->name);
            self::assertNotNull($a);
            self::assertSame($c->type, $a->type, $c->name.' keeps its type text after a MODIFY COLUMN of another column');
        }
        // Le brouillon de la table relue ne produit de nouveau plus rien.
        self::assertSame([], (new SchemaChangePlanner())->plan($this->engine, $after, self::draftOf($after))->statements);
        // Et seule la colonne touchée a changé l'empreinte, pas par la largeur : relire deux fois reste stable.
        self::assertSame(SchemaChangePlanner::fingerprint($after), SchemaChangePlanner::fingerprint($this->describe($name)));
    }

    #[DataProvider('targets')]
    public function testModifyColumnPutsTheCharsetBackToTheTableDefaultOfEachSeries(string $env): void
    {
        $this->open($env);
        self::assertSame($this->mariaDb() ? 'utf8mb4_uca1400_ai_ci' : 'utf8mb4_0900_ai_ci', $this->query('SELECT @@collation_server')[0][0]);

        // Table sans jeu de caractères explicite : celui du serveur de la série. Des colonnes portent un autre jeu (latin1) ou une autre
        // collation ; MODIFY COLUMN redit la définition sans eux, ils retombent sur ceux de la table.
        $name = $this->table('id int NOT NULL PRIMARY KEY, a varchar(20) CHARACTER SET latin1 COLLATE latin1_general_ci NULL, b varchar(20) NULL, c varchar(20) COLLATE utf8mb4_bin NULL');
        self::assertSame(['latin1', 'latin1_general_ci'], $this->charsetsOf($name)['a']);
        self::assertSame(['utf8mb4', 'utf8mb4_bin'], $this->charsetsOf($name)['c']);

        $table = $this->describe($name);
        $columns = array_map(
            static fn ($c): ColumnDraft => new ColumnDraft($c->name, $c->name, \in_array($c->name, ['a', 'b', 'c'], true) ? 'varchar(30)' : $c->type, $c->nullable, $c->default, $c->autoIncrement),
            $table->columns,
        );
        $plan = (new SchemaChangePlanner())->plan($this->engine, $table, new TableDraft($name, $columns, self::draftOf($table)->indexes, []));
        $this->target?->ddl()->execute($plan->statements, $plan->transactional);

        $after = $this->charsetsOf($name);
        $default = ['utf8mb4', $this->mariaDb() ? 'utf8mb4_uca1400_ai_ci' : 'utf8mb4_0900_ai_ci'];
        self::assertSame($default, $after['a'], 'a : MODIFY COLUMN remet le jeu de caractères de la table');
        self::assertSame($default, $after['b']);
        self::assertSame($default, $after['c'], 'c : la collation explicite est perdue aussi');
    }

    /**
     * @return array<string, array{string, string}>
     */
    private function charsetsOf(string $table): array
    {
        $out = [];
        foreach ($this->query(\sprintf("SELECT COLUMN_NAME, CHARACTER_SET_NAME, COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '%s' AND CHARACTER_SET_NAME IS NOT NULL ORDER BY ORDINAL_POSITION", $table)) as $row) {
            $out[(string) $row[0]] = [(string) $row[1], (string) $row[2]];
        }

        return $out;
    }
}
