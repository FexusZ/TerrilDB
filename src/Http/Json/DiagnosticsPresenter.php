<?php

declare(strict_types=1);

namespace App\Http\Json;

use App\Core\Diagnostics\Account;
use App\Core\Diagnostics\AccountReport;
use App\Core\Diagnostics\BlockingNode;
use App\Core\Diagnostics\ConfigAuditReport;
use App\Core\Diagnostics\ConfigFinding;
use App\Core\Diagnostics\ConnectionEncryption;
use App\Core\Diagnostics\DiagnosticStatus;
use App\Core\Diagnostics\DigestReport;
use App\Core\Diagnostics\Health\HealthIndicator;
use App\Core\Diagnostics\Health\HealthReport;
use App\Core\Diagnostics\LockReport;
use App\Core\Diagnostics\Log\ErrorLogEntry;
use App\Core\Diagnostics\Log\ErrorLogReport;
use App\Core\Diagnostics\Log\SlowQuery;
use App\Core\Diagnostics\Log\SlowQueryReport;
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
use App\Core\Diagnostics\SecurityFinding;
use App\Core\Diagnostics\ServerSession;
use App\Core\Diagnostics\ServerVariable;
use App\Core\Diagnostics\SessionReport;
use App\Core\Diagnostics\StatementDigest;
use App\Core\Diagnostics\TableHolders;
use App\Core\Diagnostics\VariableComparison;
use App\Core\Diagnostics\VariableDifference;
use App\Core\Diagnostics\VariableReport;
use App\Core\Diagnostics\Vitess\SkippedTablet;
use App\Core\Diagnostics\Vitess\TabletComparison;
use App\Core\Diagnostics\Vitess\TabletListing;
use App\Core\Diagnostics\Vitess\TabletSnapshot;
use App\Core\Diagnostics\Vitess\TabletVariableDifference;
use App\Core\Diagnostics\Vitess\VitessTablet;

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
     * Qui tient une table (pré-contrôle d'un changement de structure) : sessions, âge, mode, jamais le texte de leur requête.
     *
     * @return array{status: array<string, string|null>, holders: list<array{session: string, user: string|null, seconds: int|null, sleeping: bool, mode: string|null}>}
     */
    public static function tableHolders(TableHolders $report): array
    {
        return [
            'status' => self::status($report->status),
            'holders' => array_map(static fn ($h): array => ['session' => $h->session, 'user' => $h->user, 'seconds' => $h->seconds, 'sleeping' => $h->sleeping, 'mode' => $h->mode], $report->holders),
        ];
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
     * Les variables d'un serveur : la valeur d'un nom qui évoque un secret est déjà absente du modèle (`masked`). Rien ici n'est
     * une instruction à exécuter.
     *
     * @return array<string, mixed>
     */
    public static function variables(VariableReport $report): array
    {
        return [
            'status' => self::status($report->status),
            'truncated' => $report->truncated,
            'variables' => array_map(static fn (ServerVariable $v): array => [
                'name' => $v->name,
                'value' => $v->value,
                'default' => $v->default,
                'changed' => $v->changed,
                'masked' => $v->masked,
                'source' => $v->source,
                'restart' => $v->restart?->value,
                'scope' => $v->scope,
                'unit' => $v->unit,
            ], $report->variables),
        ];
    }

    /**
     * L'audit de configuration : des constats avec leur explication fixe et une suggestion à LIRE (jamais exécutée). `value` est ce
     * que le serveur annonce pour une variable que le modèle n'a pas masquée.
     *
     * @return array<string, mixed>
     */
    public static function configAudit(ConfigAuditReport $report): array
    {
        return [
            'status' => self::status($report->status),
            'engine' => $report->engine->value,
            'evaluated' => $report->evaluated,
            'rulesTotal' => $report->rulesTotal,
            'findings' => array_map(static fn (ConfigFinding $f): array => [
                'rule' => $f->rule->value,
                'severity' => $f->severity->value,
                'variable' => $f->variable,
                'value' => $f->value,
                'recommended' => $f->recommended,
                'suggestion' => $f->suggestion,
                'explanation' => $f->rule->explanation(),
            ], $report->findings),
        ];
    }

    /**
     * L'écart de variables de deux serveurs : aucune variable masquée n'y figure (elles sont seulement comptées).
     *
     * @return array<string, mixed>
     */
    public static function comparison(VariableComparison $comparison): array
    {
        return [
            'status' => self::status($comparison->status),
            'unavailableSide' => $comparison->unavailableSide,
            'masked' => $comparison->masked,
            'truncated' => $comparison->truncated,
            'differences' => array_map(static fn (VariableDifference $d): array => [
                'name' => $d->name,
                'presentA' => $d->presentA,
                'valueA' => $d->valueA,
                'changedA' => $d->changedA,
                'presentB' => $d->presentB,
                'valueB' => $d->valueB,
                'changedB' => $d->changedB,
                'unit' => $d->unit,
                'beyondDisplay' => $d->beyondDisplay,
            ], $comparison->differences),
        ];
    }

    /**
     * Les comptes d'un serveur et ce que l'audit en dit. Aucune empreinte de mot de passe : `passwordSet` n'est qu'un booléen.
     *
     * @return array<string, mixed>
     */
    public static function accounts(AccountReport $report): array
    {
        return [
            'status' => self::status($report->status),
            'truncated' => $report->truncated,
            'privilegesIncomplete' => $report->privilegesIncomplete,
            'activatesAllRoles' => $report->activatesAllRoles,
            'accounts' => array_map(static fn (Account $a): array => [
                'user' => $a->user,
                'host' => $a->host,
                'name' => $a->name(),
                'plugin' => $a->plugin,
                'passwordSet' => $a->passwordSet,
                'passwordExpired' => $a->passwordExpired,
                'locked' => $a->locked,
                'tls' => $a->tls->value,
                'isRole' => $a->isRole,
                'current' => $a->current,
                'roles' => $a->roles,
                'defaultRoles' => $a->defaultRoles,
                'globalPrivileges' => $a->globalPrivileges,
                'databasePrivileges' => (object) $a->databasePrivileges,
                'grantOptionOn' => $a->grantOptionOn,
                'limits' => (object) $a->limits,
            ], $report->accounts),
            'findings' => array_map(static fn (SecurityFinding $f): array => [
                'rule' => $f->rule->value,
                'severity' => $f->severity->value,
                'account' => $f->account,
                'detail' => $f->detail,
                'currentAccount' => $f->currentAccount,
            ], $report->findings()),
        ];
    }

    /**
     * Les tablets qu'on sait viser, puis (à la suite des clés historiques) ceux qu'on a écartés avec la raison : jamais un alias ni
     * une valeur du serveur.
     *
     * @return array<string, mixed>
     */
    public static function tablets(TabletListing $listing): array
    {
        return [
            'tablets' => array_map(static fn (VitessTablet $t): array => ['alias' => $t->alias, 'cell' => $t->cell, 'keyspace' => $t->keyspace, 'shard' => $t->shard, 'type' => $t->type, 'serving' => $t->serving], $listing->tablets),
            'skipped' => array_map(static fn (SkippedTablet $s): array => ['reason' => $s->reason, 'type' => $s->type], $listing->skipped),
        ];
    }

    /**
     * Les tablets côte à côte : pour chacun ce qu'on a lu (réplication, nombre de variables modifiées), puis les variables dont la valeur
     * diffère (`null` = au défaut sur ce tablet). Rien d'écrit, jamais la valeur d'un secret.
     *
     * @return array<string, mixed>
     */
    public static function tabletComparison(TabletComparison $comparison): array
    {
        return [
            'tablets' => array_map(static fn (TabletSnapshot $s): array => [
                'alias' => $s->tablet->alias,
                'cell' => $s->tablet->cell,
                'keyspace' => $s->tablet->keyspace,
                'shard' => $s->tablet->shard,
                'type' => $s->tablet->type,
                'serving' => $s->tablet->serving,
                'failure' => $s->failure,
                'replication' => null === $s->replication ? null : self::replication($s->replication),
                'variablesStatus' => null === $s->variables ? null : self::status($s->variables->status),
                'changedVariables' => null !== $s->variables && $s->variables->status->isAvailable() ? \count($s->variables->variables) : null,
            ], $comparison->snapshots),
            'differences' => array_map(static fn (TabletVariableDifference $d): array => ['name' => $d->name, 'unit' => $d->unit, 'values' => $d->values], $comparison->differences),
            'masked' => $comparison->masked,
            'truncated' => $comparison->truncated,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function encryption(ConnectionEncryption $encryption): array
    {
        return ['status' => self::status(DiagnosticStatus::available()), 'encrypted' => $encryption->encrypted, 'protocol' => $encryption->protocol, 'cipher' => $encryption->cipher];
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
     * Les empreintes les plus coûteuses : texte normalisé et raccourci (jamais de valeurs), durées en millisecondes, ratio
     * « lignes examinées / lignes renvoyées » quand la source le permet. Rien ici n'est une instruction à exécuter.
     *
     * @return array<string, mixed>
     */
    public static function statements(DigestReport $report): array
    {
        return [
            'status' => self::status($report->status),
            'order' => $report->order->value,
            'windowHours' => $report->windowHours,
            'digests' => array_map(static fn (StatementDigest $d): array => [
                'text' => $d->text,
                'textReason' => $d->textRedacted ? 'redacted' : null,
                'database' => $d->database,
                'calls' => $d->calls,
                'totalMs' => $d->totalMs,
                'meanMs' => $d->meanMs,
                'maxMs' => $d->maxMs,
                'rowsExamined' => $d->rowsExamined,
                'rowsReturned' => $d->rowsReturned,
                'ratio' => $d->ratio(),
                'noIndexUsed' => $d->noIndexUsed,
                'tmpDiskTables' => $d->tmpDiskTables,
                'sortMergePasses' => $d->sortMergePasses,
                'firstSeen' => $d->firstSeen,
                'lastSeen' => $d->lastSeen,
            ], $report->digests),
        ];
    }

    /**
     * Santé sur l'intervalle mesuré : des taux et des repères, jamais des compteurs cumulés. Vide (avec l'état ou `discarded`)
     * quand la source est illisible ou que le serveur a redémarré entre les deux relevés.
     *
     * @return array<string, mixed>
     */
    public static function health(HealthReport $report): array
    {
        return [
            'status' => self::status($report->status),
            'seconds' => null === $report->seconds ? null : round($report->seconds, 3),
            'startedAt' => self::instant($report->firstSampleAt),
            'endedAt' => self::instant($report->secondSampleAt),
            'discarded' => $report->discarded,
            'indicators' => array_map(static fn (HealthIndicator $i): array => ['key' => $i->key, 'value' => $i->value, 'unit' => $i->unit, 'level' => $i->level->value, 'threshold' => $i->threshold], $report->indicators),
        ];
    }

    /**
     * Journal d'erreurs et requêtes lentes, chacun avec son état : une source éteinte ou refusée n'est pas « aucune ligne ».
     * Les textes sont déjà masqués et raccourcis par le cœur ; rien ici n'est une instruction à exécuter.
     *
     * @return array<string, mixed>
     */
    public static function logs(ErrorLogReport $error, SlowQueryReport $slow): array
    {
        return [
            'errorLog' => [
                'status' => self::status($error->status),
                'truncated' => $error->truncated,
                'entries' => array_map(static fn (ErrorLogEntry $e): array => [
                    'loggedAt' => self::instant($e->loggedAt),
                    'level' => $e->level,
                    'subsystem' => $e->subsystem,
                    'code' => $e->code,
                    'message' => $e->message,
                ], $error->entries),
            ],
            'slowQueries' => [
                'status' => self::status($slow->status),
                'truncated' => $slow->truncated,
                'thresholdSeconds' => $slow->thresholdSeconds,
                'queries' => array_map(static fn (SlowQuery $q): array => [
                    'startedAt' => self::instant($q->startedAt),
                    'user' => $q->user,
                    'database' => $q->database,
                    'durationMs' => null === $q->durationMs ? null : round($q->durationMs, 3),
                    'lockMs' => null === $q->lockMs ? null : round($q->lockMs, 3),
                    'rowsSent' => $q->rowsSent,
                    'rowsExamined' => $q->rowsExamined,
                    'query' => $q->query,
                    'queryReason' => $q->queryRedacted ? 'redacted' : null,
                ], $slow->queries),
            ],
        ];
    }

    private static function instant(?float $at): ?string
    {
        return null === $at ? null : \sprintf('%s.%03dZ', gmdate('Y-m-d\TH:i:s', (int) floor($at)), (int) (($at - floor($at)) * 1000));
    }

    /**
     * Rôle, canaux (fils, retard, jeux de transactions, dernières erreurs neutralisées), réglages et alertes. « Pas de réplication
     * configurée » est le rôle `none` avec une source `available` ; une source illisible le dit dans `status`. Rien ici n'est une
     * instruction à exécuter (ni START, ni STOP, ni CHANGE).
     *
     * @return array<string, mixed>
     */
    public static function replication(ReplicationReport $report, bool $masked = true): array
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
                'receiverError' => self::replicationError($c->receiverError, $masked),
                'applierError' => self::replicationError($c->applierError, $masked),
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
    private static function replicationError(?ReplicationError $error, bool $masked): ?array
    {
        return null === $error ? null : ['code' => $error->code, 'message' => $masked ? $error->message : $error->unmasked];
    }
}
