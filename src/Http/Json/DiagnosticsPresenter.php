<?php

declare(strict_types=1);

namespace App\Http\Json;

use App\Core\Diagnostics\BlockingNode;
use App\Core\Diagnostics\DiagnosticStatus;
use App\Core\Diagnostics\LockReport;
use App\Core\Diagnostics\OpenTransaction;
use App\Core\Diagnostics\Replication\ReplicaLink;
use App\Core\Diagnostics\Replication\ReplicatedTable;
use App\Core\Diagnostics\Replication\ReplicationAlert;
use App\Core\Diagnostics\Replication\ReplicationAlerts;
use App\Core\Diagnostics\Replication\ReplicationChannel;
use App\Core\Diagnostics\Replication\ReplicationError;
use App\Core\Diagnostics\Replication\ReplicationExtras;
use App\Core\Diagnostics\Replication\ReplicationReport;
use App\Core\Diagnostics\Replication\ReplicationSlot;
use App\Core\Diagnostics\ServerSession;
use App\Core\Diagnostics\SessionReport;

final class DiagnosticsPresenter
{
    /**
     * @return array<string, string|null>
     */
    public static function status(DiagnosticStatus $status): array
    {
        return ['availability' => $status->availability->value, 'reason' => $status->reason, 'privilege' => $status->privilege];
    }

    /**
     * @param array<string, DiagnosticStatus> $capabilities
     *
     * @return array<string, array<string, string|null>>
     */
    public static function capabilities(array $capabilities): array
    {
        return array_map(self::status(...), $capabilities);
    }

    /**
     * @return array<string, mixed>
     */
    public static function sessions(SessionReport $report): array
    {
        return [
            'status' => self::status($report->status),
            'truncated' => $report->truncated,
            'sessions' => array_map(static fn (ServerSession $s): array => [
                'id' => $s->id,
                'user' => $s->user,
                'client' => $s->client,
                'database' => $s->database,
                'state' => $s->state,
                'seconds' => $s->seconds,
                'query' => $s->query,
                'queryReason' => $s->queryRedacted ? 'redacted' : null,
                'current' => $s->current,
            ], $report->sessions),
        ];
    }

    /**
     * Les transactions ouvertes et l'arbre « qui bloque qui » : chaque racine bloque sans attendre, ses enfants attendent après
     * elle (durée, mode et objet de l'attente en face de chaque enfant). Rien ici n'est une instruction à exécuter.
     *
     * @return array<string, mixed>
     */
    public static function locks(LockReport $report): array
    {
        $forest = $report->forest();

        return [
            'status' => self::status($report->status),
            'truncated' => $report->truncated,
            'transactions' => array_map(static fn (OpenTransaction $t): array => [
                'session' => $t->session,
                'user' => $t->user,
                'state' => $t->state,
                'seconds' => $t->seconds,
                'rowsLocked' => $t->rowsLocked,
                'rowsModified' => $t->rowsModified,
                'sleeping' => $t->sleeping,
                'query' => $t->query,
                'queryReason' => $t->queryRedacted ? 'redacted' : null,
            ], $report->transactions),
            'blocking' => array_map(self::node(...), $forest->roots),
            'blockingTruncated' => $forest->truncated,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function node(BlockingNode $node): array
    {
        return [
            'id' => $node->party->id,
            'user' => $node->party->user,
            'query' => $node->party->query,
            'queryReason' => $node->party->queryRedacted ? 'redacted' : null,
            'seconds' => $node->seconds,
            'kind' => $node->kind?->value,
            'mode' => $node->mode,
            'object' => $node->object,
            'blocked' => array_map(self::node(...), $node->blocked),
        ];
    }

    /**
     * Rôle, canaux (fils, retard, jeux de transactions, dernières erreurs neutralisées), réglages et alertes. « Pas de réplication
     * configurée » est le rôle `none` avec une source `available` ; une source illisible le dit dans `status`. Rien ici n'est une
     * instruction à exécuter (ni START, ni STOP, ni CHANGE).
     *
     * @return array<string, mixed>
     */
    public static function replication(ReplicationReport $report): array
    {
        $body = [
            'status' => self::status($report->status),
            'role' => $report->role->value,
            'replicaCount' => $report->replicaCount,
            'settings' => [
                'readOnly' => $report->settings->readOnly,
                'superReadOnly' => $report->settings->superReadOnly,
                'binlogFormat' => $report->settings->binlogFormat,
                'gtid' => $report->settings->gtid,
            ],
            'channels' => array_map(static fn (ReplicationChannel $c): array => [
                'name' => $c->name,
                'receiver' => $c->receiver->value,
                'applier' => $c->applier->value,
                'lagSeconds' => $c->lagSeconds,
                'received' => $c->received,
                'applied' => $c->applied,
                'pendingApply' => $c->pendingApply,
                'receiverError' => self::replicationError($c->receiverError),
                'applierError' => self::replicationError($c->applierError),
            ], $report->channels),
            'alerts' => array_map(static fn (ReplicationAlert $a): array => ['code' => $a->code->value, 'severity' => $a->severity->value, 'channel' => $a->channel], $report->alerts()),
            'thresholds' => ['warning' => ReplicationAlerts::LAG_WARNING_SECONDS, 'critical' => ReplicationAlerts::LAG_CRITICAL_SECONDS],
        ];

        return $body + self::replicationExtras($report->extras);
    }

    /**
     * Les sections propres au moteur : une clé n'existe que si le moteur a la notion (un rapport sans section garde la forme
     * historique), chacune avec son état. Jamais d'adresse, de chemin de coordinateur ni de texte d'erreur du moteur.
     *
     * @return array<string, mixed>
     */
    private static function replicationExtras(ReplicationExtras $extras): array
    {
        $body = [];
        if (null !== $extras->slots) {
            $body['slots'] = ['status' => self::status($extras->slots->status), 'slots' => array_map(static fn (ReplicationSlot $s): array => [
                'name' => $s->name,
                'type' => $s->type,
                'active' => $s->active,
                'temporary' => $s->temporary,
                'retainedBytes' => $s->retainedBytes,
                'walStatus' => $s->walStatus,
                'safeWalSize' => $s->safeWalSize,
            ], $extras->slots->slots)];
        }
        if (null !== $extras->replicas) {
            $body['replicas'] = ['status' => self::status($extras->replicas->status), 'replicas' => array_map(static fn (ReplicaLink $l): array => [
                'rank' => $l->rank,
                'state' => $l->state,
                'connected' => $l->connected,
                'syncState' => $l->syncState,
                'writeLagSeconds' => $l->writeLagSeconds,
                'flushLagSeconds' => $l->flushLagSeconds,
                'replayLagSeconds' => $l->replayLagSeconds,
                'behindBytes' => $l->behindBytes,
            ], $extras->replicas->replicas)];
        }
        if (null !== $extras->binlog) {
            $body['binlog'] = [
                'status' => self::status($extras->binlog->status),
                'enabled' => $extras->binlog->enabled,
                'fileCount' => $extras->binlog->fileCount,
                'totalBytes' => $extras->binlog->totalBytes,
                'expireSeconds' => $extras->binlog->expireSeconds,
                'gtidExecuted' => $extras->binlog->gtidExecuted,
                'gtidPurged' => $extras->binlog->gtidPurged,
            ];
        }
        if (null !== $extras->semiSync) {
            $body['semiSync'] = ['status' => self::status($extras->semiSync->status), 'source' => $extras->semiSync->source->value, 'replica' => $extras->semiSync->replica->value, 'clients' => $extras->semiSync->clients];
        }
        if (null !== $extras->tables) {
            $body['replicatedTables'] = ['status' => self::status($extras->tables->status), 'tables' => array_map(static fn (ReplicatedTable $t): array => [
                'database' => $t->database,
                'table' => $t->table,
                'readonly' => $t->readonly,
                'sessionExpired' => $t->sessionExpired,
                'absoluteDelaySeconds' => $t->absoluteDelaySeconds,
                'queueSize' => $t->queueSize,
                'insertsInQueue' => $t->insertsInQueue,
                'lostParts' => $t->lostParts,
            ], $extras->tables->tables), 'truncated' => $extras->tables->truncated];
        }
        if (null !== $extras->keyValue) {
            $body['keyValue'] = [
                'linkStatus' => $extras->keyValue->linkStatus,
                'lastIoSecondsAgo' => $extras->keyValue->lastIoSecondsAgo,
                'syncInProgress' => $extras->keyValue->syncInProgress,
                'backlogSize' => $extras->keyValue->backlogSize,
                'masterReplOffset' => $extras->keyValue->masterReplOffset,
            ];
        }
        if ([] !== $body) {
            $body['limits'] = ['slotRetainedWarningBytes' => ReplicationAlerts::SLOT_RETAINED_WARNING_BYTES, 'slotRetainedCriticalBytes' => ReplicationAlerts::SLOT_RETAINED_CRITICAL_BYTES, 'tableQueueWarning' => ReplicationAlerts::TABLE_QUEUE_WARNING];
        }

        return $body;
    }

    /**
     * @return array{code: int|null, message: string}|null
     */
    private static function replicationError(?ReplicationError $error): ?array
    {
        return null === $error ? null : ['code' => $error->code, 'message' => $error->message];
    }
}
