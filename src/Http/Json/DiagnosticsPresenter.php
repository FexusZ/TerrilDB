<?php

declare(strict_types=1);

namespace App\Http\Json;

use App\Core\Diagnostics\BlockingNode;
use App\Core\Diagnostics\DiagnosticStatus;
use App\Core\Diagnostics\DigestReport;
use App\Core\Diagnostics\LockReport;
use App\Core\Diagnostics\OpenTransaction;
use App\Core\Diagnostics\ServerSession;
use App\Core\Diagnostics\SessionReport;
use App\Core\Diagnostics\StatementDigest;

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
}
