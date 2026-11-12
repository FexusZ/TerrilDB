<?php

declare(strict_types=1);

namespace App\Confirmation;

use App\ApiToken\ApiTokenAccess;
use App\Audit\AuditAction;
use App\Audit\AuditContext;
use App\Audit\AuditLog;
use App\Audit\AuditOutcome;
use App\Confirmation\Exception\ConfirmationRequiredException;
use App\Confirmation\Exception\InvalidConfirmationException;
use App\Core\Driver\TargetConnection;
use App\Core\Guard\Decision;
use App\Core\Guard\GuardReason;
use App\Core\Guard\GuardVerdict;
use App\Core\Guard\StatementGuard;
use App\Core\Query\Exception\QueryNeedsConfirmationException;
use App\Core\Query\Exception\QueryRefusedException;
use App\Core\Query\QueryMode;
use App\Core\Query\QueryOptions;
use App\Core\Query\Script\Exception\ScriptNeedsConfirmationException;
use App\Core\Query\Script\Exception\ScriptRefusedException;
use App\Core\Query\Script\ScriptService;
use Symfony\Component\Uid\Uuid;

/**
 * Écrire en deux temps par un jeton d'API (API REST et MCP) : `prepare` juge l'instruction SANS rien exécuter et rend un jeton
 * de confirmation ; `authorize` ne laisse partir l'écriture que si ce jeton est valide pour ce texte exact. Un jeton d'API
 * « une seule étape » peut écrire sans préparation, mais un jeton de confirmation présenté est toujours vérifié.
 * Chaque préparation et chaque rejet sont journalisés (jamais le jeton de confirmation).
 */
final readonly class WriteApproval
{
    public function __construct(
        private StatementGuard $guard,
        private ConfirmationTickets $tickets,
        private AuditLog $audit,
        private ScriptService $scripts,
    ) {
    }

    /**
     * @throws \App\ApiToken\Exception\ApiTokenPermissionException jeton sans droit d'écriture
     * @throws QueryRefusedException                               refus définitif : aucun jeton n'est émis
     */
    public function prepare(ApiTokenAccess $access, AuditContext $context, Uuid $connectionId, TargetConnection $target, string $sql): PreparedWrite
    {
        $options = $access->writeOptions(QueryOptions::MIN_TIMEOUT_MS, 1);
        $verdict = $this->guard->check($sql, $target->engine, QueryMode::ReadWrite, $options->writes);
        $base = ['sql' => $sql, 'engine' => $target->engine->value, 'mode' => QueryMode::ReadWrite->value];

        if (Decision::Refused === $verdict->decision || null === $verdict->kind) {
            $reason = $verdict->reason ?? GuardReason::UnsupportedStatement;
            $this->audit->record($context, AuditAction::QueryRefused, AuditOutcome::Refused, $connectionId, $base + ['reason' => $reason->value, 'purpose' => 'prepare']);

            throw new QueryRefusedException($reason);
        }

        $decision = self::decision($verdict);
        $issued = $this->tickets->issue($access->token, new ConfirmationBinding($access->token->getId(), $connectionId, ConfirmationKind::Statement, $sql, $decision));
        $prepared = new PreparedWrite($decision, $verdict->kind, $verdict->reason, $issued);
        $this->audit->record($context, AuditAction::QueryPrepared, AuditOutcome::Success, $connectionId, $base + [
            'decision' => $decision,
            'kind' => strtolower($verdict->kind->name),
            'danger' => $prepared->danger(),
            'reason' => $verdict->reason?->value,
        ]);

        return $prepared;
    }

    /**
     * À appeler AVANT l'exécution d'une écriture par un jeton d'API.
     *
     * @throws ConfirmationRequiredException   jeton à deux étapes sans jeton de confirmation
     * @throws InvalidConfirmationException    jeton de confirmation invalide, expiré, rejoué ou pour un autre texte
     * @throws QueryRefusedException
     * @throws QueryNeedsConfirmationException destructif sans `confirmed` (le jeton de confirmation n'est alors pas consommé)
     */
    public function authorize(ApiTokenAccess $access, AuditContext $context, Uuid $connectionId, TargetConnection $target, string $sql, ?string $confirmationToken, bool $confirmed): void
    {
        $base = ['sql' => $sql, 'engine' => $target->engine->value, 'mode' => QueryMode::ReadWrite->value];

        if (null === $confirmationToken) {
            if ($access->token->getPermissions()->singleStep) {
                return;
            }
            $this->audit->record($context, AuditAction::QueryRefused, AuditOutcome::Refused, $connectionId, $base + ['reason' => 'confirmation_required']);

            throw ConfirmationRequiredException::create();
        }

        $options = $access->writeOptions(QueryOptions::MIN_TIMEOUT_MS, 1);
        $verdict = $this->guard->check($sql, $target->engine, QueryMode::ReadWrite, $options->writes);
        if (Decision::Refused === $verdict->decision || null === $verdict->kind) {
            $reason = $verdict->reason ?? GuardReason::UnsupportedStatement;
            $this->audit->record($context, AuditAction::QueryRefused, AuditOutcome::Refused, $connectionId, $base + ['reason' => $reason->value]);

            throw new QueryRefusedException($reason);
        }
        if (Decision::NeedsConfirmation === $verdict->decision && !$confirmed) {
            throw new QueryNeedsConfirmationException($verdict->reason ?? GuardReason::DestructiveStatement);
        }

        try {
            $this->tickets->redeem($confirmationToken, new ConfirmationBinding($access->token->getId(), $connectionId, ConfirmationKind::Statement, $sql, self::decision($verdict)));
        } catch (InvalidConfirmationException $e) {
            $this->audit->record($context, AuditAction::QueryRefused, AuditOutcome::Refused, $connectionId, $base + ['reason' => 'invalid_confirmation']);

            throw $e;
        }
    }

    /**
     * Première étape d'un SCRIPT : chaque instruction est jugée avec les droits du jeton, rien n'est exécuté, UN jeton de
     * confirmation couvre le script entier (texte exact). Un refus d'une seule instruction = aucun jeton.
     *
     * @throws ScriptRefusedException
     */
    public function prepareScript(ApiTokenAccess $access, AuditContext $context, Uuid $connectionId, TargetConnection $target, string $script): PreparedScript
    {
        $writes = $access->writeOptions(QueryOptions::MIN_TIMEOUT_MS, 1)->writes;
        $preview = $this->scripts->preview($script, $target->engine, $writes);
        $report = $preview->report;
        if ([] === $report->steps) {
            throw new QueryRefusedException(GuardReason::EmptyStatement);
        }
        if (null !== $report->firstRefused) {
            $reason = $report->firstRefused->verdict->reason ?? GuardReason::UnsupportedStatement;
            $this->audit->record($context, AuditAction::QueryRefused, AuditOutcome::Refused, $connectionId, [
                'sql' => $report->firstRefused->sql, 'engine' => $target->engine->value, 'reason' => $reason->value, 'purpose' => 'prepare', 'script' => true,
                'rank' => $report->firstRefused->index + 1, 'total' => \count($report->steps),
            ]);

            throw new ScriptRefusedException($reason, $report->firstRefused->index);
        }

        $decision = $report->needsConfirmation() ? 'needs_confirmation' : 'allowed';
        $issued = $this->tickets->issue($access->token, new ConfirmationBinding($access->token->getId(), $connectionId, ConfirmationKind::Script, $script, $decision));
        $this->audit->record($context, AuditAction::QueryPrepared, AuditOutcome::Success, $connectionId, [
            'script' => true, 'engine' => $target->engine->value, 'decision' => $decision, 'statements' => \count($report->steps),
            'destructive' => $report->destructiveCount(), 'danger' => 'needs_confirmation' === $decision ? 'destructive' : 'normal',
        ]);

        return new PreparedScript($preview, $decision, $issued);
    }

    /**
     * À appeler AVANT l'exécution d'un script par un jeton d'API. Un script exige TOUJOURS un jeton de confirmation, même pour
     * un jeton « une seule étape » : c'est jusqu'à 500 écritures d'un coup.
     *
     * @throws ConfirmationRequiredException
     * @throws InvalidConfirmationException
     * @throws ScriptRefusedException
     * @throws ScriptNeedsConfirmationException destructif sans `confirmed` (le jeton n'est alors pas consommé)
     */
    public function authorizeScript(ApiTokenAccess $access, AuditContext $context, Uuid $connectionId, TargetConnection $target, string $script, ?string $confirmationToken, bool $confirmed): void
    {
        if (null === $confirmationToken) {
            $this->audit->record($context, AuditAction::QueryRefused, AuditOutcome::Refused, $connectionId, ['script' => true, 'engine' => $target->engine->value, 'reason' => 'confirmation_required']);

            throw ConfirmationRequiredException::create();
        }

        $writes = $access->writeOptions(QueryOptions::MIN_TIMEOUT_MS, 1)->writes;
        $report = $this->scripts->preview($script, $target->engine, $writes)->report;
        if ([] === $report->steps) {
            throw new QueryRefusedException(GuardReason::EmptyStatement);
        }
        if (null !== $report->firstRefused) {
            throw new ScriptRefusedException($report->firstRefused->verdict->reason ?? GuardReason::UnsupportedStatement, $report->firstRefused->index);
        }
        if ($report->needsConfirmation() && !$confirmed) {
            throw new ScriptNeedsConfirmationException($report->destructiveCount());
        }

        try {
            $this->tickets->redeem($confirmationToken, new ConfirmationBinding($access->token->getId(), $connectionId, ConfirmationKind::Script, $script, $report->needsConfirmation() ? 'needs_confirmation' : 'allowed'));
        } catch (InvalidConfirmationException $e) {
            $this->audit->record($context, AuditAction::QueryRefused, AuditOutcome::Refused, $connectionId, ['script' => true, 'engine' => $target->engine->value, 'reason' => 'invalid_confirmation']);

            throw $e;
        }
    }

    private static function decision(GuardVerdict $verdict): string
    {
        return Decision::NeedsConfirmation === $verdict->decision ? 'needs_confirmation' : 'allowed';
    }
}
