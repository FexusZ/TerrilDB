<?php

declare(strict_types=1);

namespace App\Core\Diagnostics\Vitess;

/**
 * Un tablet que vtgate sait viser : `SHOW VITESS_TABLETS` en donne une ligne, `USE keyspace:shard@var|alias` le choisit.
 * Les valeurs viennent du serveur (donc hostiles) : seules celles qui ont la forme attendue deviennent un nom de cible.
 */
final readonly class VitessTablet
{
    private const ALIAS = '/^[A-Za-z0-9_]{1,40}-[0-9]{1,10}$/D';
    private const NAME = '/^[A-Za-z0-9_.-]{1,64}$/D';
    private const SHARD = '/^[A-Za-z0-9_.-]{1,64}$/D';
    private const TARGETABLE = ['PRIMARY' => 'primary', 'REPLICA' => 'replica', 'RDONLY' => 'rdonly'];
    /** Les types de tablet que Vitess connaît ; une autre valeur du serveur n'est jamais rendue telle quelle. */
    private const KNOWN_TYPES = ['PRIMARY', 'MASTER', 'REPLICA', 'RDONLY', 'BATCH', 'SPARE', 'EXPERIMENTAL', 'BACKUP', 'RESTORE', 'DRAINED', 'UNKNOWN'];

    public function __construct(
        public string $alias,
        public string $cell,
        public string $keyspace,
        public string $shard,
        public string $type,
        public bool $serving,
    ) {
    }

    public static function isAlias(string $value): bool
    {
        return 1 === preg_match(self::ALIAS, $value);
    }

    /**
     * Un nom de base qui vise un tablet précis (`keyspace:shard@var|alias`), par opposition à un keyspace ou à `keyspace@replica`.
     */
    public static function isTarget(string $database): bool
    {
        return 1 === preg_match('/^[A-Za-z0-9_.-]{1,64}:[A-Za-z0-9_.-]{1,64}@(?:primary|replica|rdonly)\|[A-Za-z0-9_]{1,40}-[0-9]{1,10}$/D', $database);
    }

    /**
     * @param array<string, mixed> $row une ligne de `SHOW VITESS_TABLETS`
     */
    public static function fromRow(array $row): ?self
    {
        $alias = $row['Alias'] ?? null;
        $cell = $row['Cell'] ?? null;
        $keyspace = $row['Keyspace'] ?? null;
        $shard = $row['Shard'] ?? null;
        $type = $row['TabletType'] ?? null;
        $state = $row['State'] ?? null;
        if (!\is_string($alias) || !\is_string($cell) || !\is_string($keyspace) || !\is_string($shard) || !\is_string($type) || !\is_string($state)) {
            return null;
        }
        if (!self::isAlias($alias) || 1 !== preg_match(self::NAME, $keyspace) || 1 !== preg_match(self::SHARD, $shard) || 1 !== preg_match(self::NAME, $cell)) {
            return null;
        }
        $type = strtoupper($type);
        if (!isset(self::TARGETABLE[$type])) {
            return null;
        }

        return new self($alias, $cell, $keyspace, $shard, $type, 'SERVING' === strtoupper($state));
    }

    /**
     * Pourquoi une ligne que `fromRow` refuse n'est pas proposée : le type d'abord (une sauvegarde en cours n'est pas une panne), puis
     * la forme des noms, puis des colonnes absentes.
     *
     * @param array<string, mixed> $row une ligne de `SHOW VITESS_TABLETS`
     */
    public static function skipped(array $row): SkippedTablet
    {
        foreach (['Alias', 'Cell', 'Keyspace', 'Shard', 'TabletType', 'State'] as $column) {
            if (!\is_string($row[$column] ?? null)) {
                return new SkippedTablet(SkippedTablet::UNREADABLE, null);
            }
        }
        $type = strtoupper($row['TabletType']);
        $known = \in_array($type, self::KNOWN_TYPES, true) ? $type : null;

        return new SkippedTablet(isset(self::TARGETABLE[$type]) ? SkippedTablet::UNEXPECTED_NAME : SkippedTablet::TYPE_NOT_TARGETABLE, $known);
    }

    /**
     * Le nom de base qui envoie la session à CE tablet (mesuré sur vtgate : `shop:0@replica|test-0000000002`).
     */
    public function target(): string
    {
        return \sprintf('%s:%s@%s|%s', $this->keyspace, $this->shard, self::TARGETABLE[$this->type], $this->alias);
    }
}
