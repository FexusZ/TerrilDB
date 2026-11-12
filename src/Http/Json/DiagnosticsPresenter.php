<?php

declare(strict_types=1);

namespace App\Http\Json;

use App\Core\Diagnostics\Account;
use App\Core\Diagnostics\AccountReport;
use App\Core\Diagnostics\BlockingNode;
use App\Core\Diagnostics\ConnectionEncryption;
use App\Core\Diagnostics\DiagnosticStatus;
use App\Core\Diagnostics\LockReport;
use App\Core\Diagnostics\OpenTransaction;
use App\Core\Diagnostics\SecurityFinding;
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
     * Les comptes d'un serveur et ce que l'audit en dit. Aucune empreinte de mot de passe : `passwordSet` n'est qu'un booléen.
     *
     * @return array<string, mixed>
     */
    public static function accounts(AccountReport $report): array
    {
        return [
            'status' => self::status($report->status),
            'truncated' => $report->truncated,
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
}
