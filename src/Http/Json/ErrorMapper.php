<?php

declare(strict_types=1);

namespace App\Http\Json;

use App\ApiToken\Exception\AdministratorTokenNeedsConfirmationException;
use App\ApiToken\Exception\ApiTokenNotFoundException;
use App\ApiToken\Exception\ApiTokenPermissionException;
use App\ApiToken\Exception\InvalidApiTokenException;
use App\Confirmation\Exception\ConfirmationRequiredException;
use App\Confirmation\Exception\InvalidConfirmationException;
use App\Connection\Exception\ConnectionNameAlreadyUsedException;
use App\Connection\Exception\ConnectionNotFoundException;
use App\Connection\Exception\ForbiddenTargetHostException;
use App\Connection\Exception\InvalidConnectionOrderException;
use App\Core\Connection\Exception\ForbiddenBastionException;
use App\Core\Connection\Exception\InvalidConnectionParametersException;
use App\Core\Connection\Tunnel\TooManyTunnelsException;
use App\Core\Connection\Tunnel\TunnelFailedException;
use App\Core\Data\Exception\DataChangeConflictException;
use App\Core\Data\Exception\DataChangeFailedException;
use App\Core\Data\Exception\DataChangeNeedsConfirmationException;
use App\Core\Data\Exception\InvalidDataChangeException;
use App\Core\Diagnostics\Exception\DiagnosticsFailedException;
use App\Core\Diagnostics\Exception\InvalidDigestQueryException;
use App\Core\Diagnostics\Exception\InvalidVariableQueryException;
use App\Core\Diagnostics\Health\HealthBusyException;
use App\Core\Driver\DetailedErrors;
use App\Core\Driver\EngineFault;
use App\Core\Driver\Exception\EngineFaultException;
use App\Core\Driver\Exception\LockConflictException;
use App\Core\Driver\Exception\TargetConnectionFailedException;
use App\Core\Driver\Exception\UnsupportedEngineException;
use App\Core\Driver\Exception\UnsupportedOperationException;
use App\Core\Driver\LockConflict;
use App\Core\Guard\Exception\ScriptTooLargeException;
use App\Core\Guard\Exception\UnsplittableScriptException;
use App\Core\KeyValue\Exception\InvalidKeyException;
use App\Core\KeyValue\Exception\KeyNotFoundException;
use App\Core\Optimizer\Exception\MeasureBusyException;
use App\Core\Optimizer\Exception\PlanNotAvailableException;
use App\Core\Optimizer\Exception\UnreadablePlanException;
use App\Core\Query\Exception\InvalidQueryOptionsException;
use App\Core\Query\Exception\QueryFailedException;
use App\Core\Query\Exception\QueryNeedsConfirmationException;
use App\Core\Query\Exception\QueryRefusedException;
use App\Core\Query\Exception\QueryTimeoutException;
use App\Core\Query\Script\Exception\ScriptDryRunUnavailableException;
use App\Core\Query\Script\Exception\ScriptNeedsConfirmationException;
use App\Core\Query\Script\Exception\ScriptRefusedException;
use App\Core\Schema\Change\Exception\BigTableNeedsConfirmationException;
use App\Core\Schema\Change\Exception\InvalidSchemaChangeException;
use App\Core\Schema\Change\Exception\SchemaChangeFailedException;
use App\Core\Schema\Change\Exception\SchemaChangeNeedsConfirmationException;
use App\Core\Schema\Change\Exception\StaleSchemaException;
use App\Core\Schema\Change\Exception\UnsupportedPartitionChangeException;
use App\Core\Schema\Change\Exception\UnsupportedSchemaChangeException;
use App\Core\Schema\Exception\TableNotFoundException;
use App\Crypto\Exception\CryptoException;
use App\Entity\Exception\InvalidApiTokenDefinitionException;
use App\Entity\Exception\InvalidDatabaseConnectionException;
use App\Entity\Exception\InvalidInvitationException;
use App\Entity\Exception\InvalidUserException;
use App\Invitation\Exception\InvalidInvitationTokenException;
use App\Invitation\Exception\InvitationNotFoundException;
use App\Invitation\Exception\MailDisabledException;
use App\Invitation\Exception\NotAnAdministratorException;
use App\ScriptRun\Channel\ScriptWorkerBusyException;
use App\ScriptRun\Channel\ScriptWorkerUnavailableException;
use App\ScriptRun\Exception\InvalidScriptRunRequestException;
use App\ScriptRun\Exception\ScriptRunFinishedException;
use App\ScriptRun\Exception\ScriptRunNotFoundException;
use App\Security\Exception\AccountAlreadyExistsException;
use App\Security\Exception\AccountNotFoundException;
use App\Security\Exception\CannotDisableAccountException;
use App\Security\Exception\InvalidCredentialsException;
use App\Security\Exception\InvalidRecoveryException;
use App\Security\Exception\InvalidSecondFactorException;
use App\Security\Exception\PasswordPolicyException;
use App\Security\Exception\SecondFactorRequiredException;
use App\Security\Exception\TotpAlreadyEnabledException;
use App\Security\Exception\TotpNotEnabledException;

/**
 * Traduit une erreur du domaine en réponse HTTP. Le client ne voit que des codes stables et, quand c'est sans
 * risque, un détail : jamais le message du moteur pour une connexion injoignable (il peut citer l'hôte),
 * jamais une valeur saisie.
 */
final class ErrorMapper
{
    public function __construct(private readonly DetailedErrors $detailed = new DetailedErrors())
    {
    }

    public function map(\Throwable $error): ?ErrorResponse
    {
        if (!$this->detailed->enabled) {
            $error = self::withoutDetail($error);
        }

        return match (true) {
            $error instanceof InvalidCredentialsException => new ErrorResponse(401, 'invalid_credentials'),
            $error instanceof SecondFactorRequiredException => new ErrorResponse(401, 'second_factor_required'),
            $error instanceof InvalidSecondFactorException => new ErrorResponse(401, 'invalid_second_factor'),
            $error instanceof LockedException, $error instanceof CryptoException => new ErrorResponse(401, 'locked'),
            $error instanceof MeasureBusyException => new ErrorResponse(429, 'too_many_attempts', ['retryAfter' => $error->retryAfter], ['Retry-After' => (string) $error->retryAfter]),
            $error instanceof TooManyAttemptsException => new ErrorResponse(429, 'too_many_attempts', ['retryAfter' => $error->retryAfter], ['Retry-After' => (string) $error->retryAfter]),
            $error instanceof HealthBusyException => new ErrorResponse(429, 'too_many_attempts', ['retryAfter' => $error->retryAfter], ['Retry-After' => (string) $error->retryAfter]),
            $error instanceof TooManyTunnelsException => new ErrorResponse(429, 'too_many_tunnels'),
            $error instanceof BadRequestException => new ErrorResponse(400, 'bad_request', ['message' => $error->getMessage()]),
            $error instanceof PasswordPolicyException => new ErrorResponse(422, 'weak_password', ['message' => $error->getMessage()]),
            $error instanceof InvalidApiTokenException => new ErrorResponse(401, 'invalid_token'),
            $error instanceof ApiTokenPermissionException => new ErrorResponse(403, 'permission_denied'),
            $error instanceof ForbiddenTargetHostException => new ErrorResponse(403, 'forbidden_target'),
            $error instanceof ForbiddenBastionException => new ErrorResponse(403, 'forbidden_bastion'),
            $error instanceof TunnelFailedException => new ErrorResponse(502, 'tunnel_failed', ['reason' => $error->reason]),
            $error instanceof NotAnAdministratorException => new ErrorResponse(403, 'forbidden'),
            $error instanceof InvalidInvitationTokenException => new ErrorResponse(403, 'invalid_invitation'),
            $error instanceof InvalidUserException,
            $error instanceof InvalidInvitationException,
            $error instanceof InvalidApiTokenDefinitionException,
            $error instanceof InvalidDatabaseConnectionException,
            $error instanceof InvalidConnectionOrderException,
            $error instanceof InvalidConnectionParametersException,
            $error instanceof InvalidKeyException,
            $error instanceof InvalidDigestQueryException,
            $error instanceof InvalidVariableQueryException,
            $error instanceof InvalidQueryOptionsException => new ErrorResponse(422, 'invalid_input', ['message' => $error->getMessage()]),
            $error instanceof InvalidRecoveryException => new ErrorResponse(403, 'invalid_recovery'),
            $error instanceof MailDisabledException => new ErrorResponse(409, 'mail_disabled'),
            $error instanceof CannotDisableAccountException => new ErrorResponse(409, $error->reason),
            $error instanceof AccountAlreadyExistsException => new ErrorResponse(409, 'account_exists'),
            $error instanceof TotpAlreadyEnabledException => new ErrorResponse(409, 'totp_already_enabled'),
            $error instanceof TotpNotEnabledException => new ErrorResponse(409, 'totp_not_enabled'),
            $error instanceof ConnectionNameAlreadyUsedException => new ErrorResponse(409, 'connection_name_used'),
            $error instanceof ConnectionNotFoundException, $error instanceof ScriptRunNotFoundException, $error instanceof TableNotFoundException, $error instanceof KeyNotFoundException, $error instanceof ApiTokenNotFoundException, $error instanceof InvitationNotFoundException, $error instanceof AccountNotFoundException => new ErrorResponse(404, 'not_found'),
            $error instanceof TargetConnectionFailedException => null !== $error->fault ? self::fault($error->fault) : new ErrorResponse(502, 'target_unreachable'),
            $error instanceof UnsupportedEngineException => new ErrorResponse(501, 'unsupported_engine'),
            $error instanceof QueryRefusedException => new ErrorResponse(403, 'query_refused', ['reason' => $error->reason->value]),
            $error instanceof QueryNeedsConfirmationException => new ErrorResponse(409, 'confirmation_required', ['reason' => $error->reason->value]),
            $error instanceof ScriptWorkerBusyException => new ErrorResponse(503, 'script_worker_busy', ['retryable' => true, 'retryAfter' => 5], ['Retry-After' => '5']),
            $error instanceof ScriptWorkerUnavailableException => new ErrorResponse(503, 'script_worker_unavailable', ['retryable' => false]),
            $error instanceof ScriptRunFinishedException => new ErrorResponse(409, 'run_finished'),
            $error instanceof InvalidScriptRunRequestException => new ErrorResponse(422, 'invalid_script', ['reason' => 'invalid_run_request']),
            $error instanceof ScriptRefusedException => new ErrorResponse(403, 'script_refused', ['reason' => $error->reason->value, 'statement' => $error->statementIndex]),
            $error instanceof ScriptNeedsConfirmationException => new ErrorResponse(409, 'confirmation_required', ['reason' => $error->reason, 'destructiveCount' => $error->destructiveCount]),
            $error instanceof ScriptDryRunUnavailableException => new ErrorResponse(409, 'dry_run_unavailable', ['reason' => $error->reason]),
            $error instanceof UnsplittableScriptException => new ErrorResponse(422, 'invalid_script', ['detail' => $error->getMessage(), 'reason' => $error->reason]),
            $error instanceof ScriptTooLargeException => new ErrorResponse(422, 'script_too_large', ['detail' => $error->getMessage()]),
            $error instanceof AdministratorTokenNeedsConfirmationException => new ErrorResponse(409, 'confirmation_required', ['reason' => AdministratorTokenNeedsConfirmationException::REASON]),
            $error instanceof ConfirmationRequiredException => new ErrorResponse(409, 'preparation_required'),
            $error instanceof InvalidConfirmationException => new ErrorResponse(403, 'invalid_confirmation'),
            $error instanceof InvalidDataChangeException => new ErrorResponse(422, 'invalid_data_change', ['detail' => $error->getMessage()]),
            $error instanceof DataChangeConflictException => new ErrorResponse(409, 'data_changed', ['statement' => $error->statementIndex, 'matched' => $error->matched]),
            $error instanceof DataChangeNeedsConfirmationException => new ErrorResponse(409, 'confirmation_required', ['reason' => 'delete_rows'] + DataPlanPresenter::plan($error->plan)),
            $error instanceof LockConflictException => new ErrorResponse(409, $error->conflict->code(), ['retryable' => true]),
            $error instanceof EngineFaultException => self::fault($error->fault),
            $error instanceof DataChangeFailedException && null !== $error->fault => self::fault($error->fault, ['retryable' => $error->fault->retryable(), 'applied' => $error->applied, 'failed' => DataPlanPresenter::statement($error->failed, null)]),
            $error instanceof DataChangeFailedException => null !== $error->lock
                ? new ErrorResponse(409, $error->lock->code(), ['retryable' => true, 'applied' => $error->applied, 'failed' => DataPlanPresenter::statement($error->failed, null)])
                : new ErrorResponse(409, 'data_change_failed', [
                    'message' => $error->getMessage(),
                    'retryable' => false,
                    'applied' => $error->applied,
                    'failed' => DataPlanPresenter::statement($error->failed, null),
                ]),
            $error instanceof InvalidSchemaChangeException => new ErrorResponse(422, 'invalid_schema_change', ['detail' => $error->getMessage()]),
            $error instanceof UnsupportedPartitionChangeException => new ErrorResponse(422, 'unsupported_partition_change', ['reason' => $error->reason, 'subject' => $error->subject]),
            $error instanceof UnsupportedSchemaChangeException => new ErrorResponse(422, 'unsupported_schema_change', ['reason' => $error->reason->value, 'subject' => $error->subject]),
            $error instanceof StaleSchemaException => new ErrorResponse(409, 'schema_changed'),
            $error instanceof BigTableNeedsConfirmationException => new ErrorResponse(409, 'confirmation_required', ['reason' => 'big_table_name', 'bigTable' => ChangePlanPresenter::bigTable($error->assessment)]),
            $error instanceof SchemaChangeNeedsConfirmationException => new ErrorResponse(409, 'confirmation_required', ['reason' => 'destructive_schema_change'] + ChangePlanPresenter::plan($error->plan)),
            $error instanceof SchemaChangeFailedException && null !== $error->fault => self::fault($error->fault, [
                'retryable' => $error->fault->retryable() && [] === $error->applied,
                'applied' => array_map(ChangePlanPresenter::statement(...), $error->applied),
                'failed' => ChangePlanPresenter::statement($error->failed),
            ]),
            $error instanceof SchemaChangeFailedException => null !== $error->lock
                ? new ErrorResponse(409, $error->lock->code(), [
                    'retryable' => [] === $error->applied,
                    'applied' => array_map(ChangePlanPresenter::statement(...), $error->applied),
                    'failed' => ChangePlanPresenter::statement($error->failed),
                    'lockTimeout' => LockConflict::LockTimeout === $error->lock,
                    'lockCheck' => null === $error->holders ? null : DiagnosticsPresenter::tableHolders($error->holders),
                ])
                : new ErrorResponse(409, 'schema_change_failed', [
                    'message' => $error->getMessage(),
                    'retryable' => false,
                    'applied' => array_map(ChangePlanPresenter::statement(...), $error->applied),
                    'failed' => ChangePlanPresenter::statement($error->failed),
                ]),
            $error instanceof DiagnosticsFailedException => new ErrorResponse(502, 'diagnostics_failed'),
            $error instanceof UnsupportedOperationException => new ErrorResponse(501, 'unsupported_operation'),
            $error instanceof PlanNotAvailableException => new ErrorResponse(501, 'plan_unavailable'),
            $error instanceof UnreadablePlanException => new ErrorResponse(502, 'unreadable_plan'),
            $error instanceof QueryTimeoutException => new ErrorResponse(408, 'query_timeout'),
            $error instanceof QueryFailedException => new ErrorResponse(400, 'query_failed', ['message' => $error->getMessage()]),
            default => null,
        };
    }

    /**
     * @param array<string, mixed>|null $extra remplace les détails par défaut (`retryable`, et `retryAfter` pour un serveur plein)
     */
    private static function fault(EngineFault $fault, ?array $extra = null): ErrorResponse
    {
        $seconds = $fault->retryAfterSeconds();
        $extra ??= ['retryable' => $fault->retryable()];
        $advise = null !== $seconds && true === ($extra['retryable'] ?? false);
        if ($advise) {
            $extra['retryAfter'] = $seconds;
        }

        return new ErrorResponse(
            match ($fault) {
                EngineFault::TooManyConnections => 503,
                EngineFault::PermissionDenied => 403,
                EngineFault::DuplicateKey => 409,
                EngineFault::AuthRequiresTls, EngineFault::AuthenticationFailed => 502,
            },
            $fault->code(),
            $extra,
            $advise ? ['Retry-After' => (string) $seconds] : [],
        );
    }

    /**
     * Sans `TERRILDB_DETAILED_ERRORS` : l'erreur reconnue redevient celle d'origine (même code, même corps), pour ne rien apprendre de plus
     * sur la machine visée.
     */
    private static function withoutDetail(\Throwable $error): \Throwable
    {
        return match (true) {
            $error instanceof EngineFaultException => new QueryFailedException($error->engineMessage, previous: $error->getPrevious()),
            $error instanceof TargetConnectionFailedException && null !== $error->fault => TargetConnectionFailedException::create($error->getPrevious()),
            $error instanceof DataChangeFailedException && null !== $error->fault => new DataChangeFailedException($error->getMessage(), $error->applied, $error->failed, $error->getPrevious(), $error->lock),
            $error instanceof SchemaChangeFailedException && null !== $error->fault => new SchemaChangeFailedException($error->getMessage(), $error->applied, $error->failed, $error->getPrevious(), $error->lock, $error->holders),
            default => $error,
        };
    }
}
