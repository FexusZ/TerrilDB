<?php

declare(strict_types=1);

namespace App\Core\Diagnostics\Vitess;

use App\Core\Diagnostics\Log\LogMessage;
use App\Core\Diagnostics\QueryText;
use App\Core\Guard\SqlDialect;

/**
 * Une migration de DDL en ligne (une ligne de `SHOW VITESS_MIGRATIONS`). Tout vient du serveur donc d'une donnée : l'UUID doit avoir la forme
 * attendue (sinon la ligne est écartée, elle ne pourra jamais devenir un argument d'instruction), l'état est pris dans une liste fermée,
 * l'instruction a ses littéraux masqués et le message d'erreur est débarrassé de ce qui ressemble à une valeur ou une adresse.
 */
final readonly class VitessMigration
{
    private const STATUSES = ['queued', 'ready', 'running', 'complete', 'failed', 'cancelled'];

    public function __construct(
        public string $uuid,
        public string $keyspace,
        public string $shard,
        public string $table,
        public string $status,
        public int $progress,
        public ?string $statement,
        public string $strategy,
        public ?string $addedAt,
        public ?string $startedAt,
        public ?string $completedAt,
        public ?string $message,
        public int $retries,
        public ?int $etaSeconds,
        public bool $postponeCompletion,
        public bool $postponeLaunch,
        public ?string $tablet,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): ?self
    {
        $uuid = $row['migration_uuid'] ?? null;
        if (!\is_string($uuid) || !MigrationAction::isUuid($uuid)) {
            return null;
        }
        $status = self::text($row['migration_status'] ?? null);
        $eta = self::integer($row['eta_seconds'] ?? null);

        return new self(
            $uuid,
            self::name($row['keyspace'] ?? null),
            self::name($row['shard'] ?? null),
            self::name($row['mysql_table'] ?? null),
            null !== $status && \in_array($status, self::STATUSES, true) ? $status : 'unknown',
            max(0, min(100, self::integer($row['progress'] ?? null) ?? 0)),
            QueryText::of(self::text($row['migration_statement'] ?? null), SqlDialect::MySql)->text,
            self::name($row['strategy'] ?? null),
            self::text($row['added_timestamp'] ?? null),
            self::text($row['started_timestamp'] ?? null),
            self::text($row['completed_timestamp'] ?? null),
            LogMessage::of(self::text($row['message'] ?? null)),
            max(0, self::integer($row['retries'] ?? null) ?? 0),
            null === $eta || $eta < 0 ? null : $eta,
            1 === self::integer($row['postpone_completion'] ?? null),
            1 === self::integer($row['postpone_launch'] ?? null),
            self::text($row['tablet'] ?? null),
        );
    }

    /**
     * Ce qui a un sens pour cet état : annuler tant que ça n'a pas fini, rejouer ce qui a échoué ou a été annulé, terminer ou lancer ce
     * qui attend qu'on le décide. Une migration finie ou d'un état inconnu n'offre rien.
     *
     * @return list<MigrationAction>
     */
    public function actions(): array
    {
        return match ($this->status) {
            'queued', 'ready' => $this->postponeLaunch ? [MigrationAction::Launch, MigrationAction::Cancel] : [MigrationAction::Cancel],
            'running' => $this->postponeCompletion ? [MigrationAction::Complete, MigrationAction::Cancel] : [MigrationAction::Cancel],
            'failed', 'cancelled' => [MigrationAction::Retry],
            default => [],
        };
    }

    private static function text(mixed $value): ?string
    {
        return \is_string($value) && '' !== $value ? mb_scrub($value, 'UTF-8') : null;
    }

    private static function integer(mixed $value): ?int
    {
        return \is_int($value) || (\is_string($value) && 1 === preg_match('/^-?[0-9]{1,18}$/D', $value)) ? (int) $value : null;
    }

    /** Un nom (keyspace, shard, table, stratégie) : court, sans caractère de contrôle, jamais rendu tel quel au-delà. */
    private static function name(mixed $value): string
    {
        $text = self::text($value) ?? '';
        $text = preg_replace('/[\x00-\x1F\x7F]/u', '', $text) ?? '';

        return mb_substr($text, 0, 128);
    }
}
