<?php

declare(strict_types=1);

namespace App\Http\Json;

use App\ApiToken\Exception\ApiTokenNotFoundException;
use App\ApiToken\Exception\ApiTokenPermissionException;
use App\ApiToken\Exception\InvalidApiTokenException;
use App\Connection\Exception\ConnectionNameAlreadyUsedException;
use App\Connection\Exception\ConnectionNotFoundException;
use App\Connection\Exception\ForbiddenTargetHostException;
use App\Connection\Exception\InvalidConnectionOrderException;
use App\Core\Connection\Exception\InvalidConnectionParametersException;
use App\Core\Data\Exception\DataChangeConflictException;
use App\Core\Data\Exception\DataChangeFailedException;
use App\Core\Data\Exception\DataChangeNeedsConfirmationException;
use App\Core\Data\Exception\InvalidDataChangeException;
use App\Core\Diagnostics\Exception\DiagnosticsFailedException;
use App\Core\Driver\Exception\LockConflictException;
use App\Core\Driver\Exception\TargetConnectionFailedException;
use App\Core\Driver\Exception\UnsupportedEngineException;
use App\Core\Driver\Exception\UnsupportedOperationException;
use App\Core\KeyValue\Exception\InvalidKeyException;
use App\Core\KeyValue\Exception\KeyNotFoundException;
use App\Core\Optimizer\Exception\PlanNotAvailableException;
use App\Core\Optimizer\Exception\UnreadablePlanException;
use App\Core\Query\Exception\InvalidQueryOptionsException;
use App\Core\Query\Exception\QueryFailedException;
use App\Core\Query\Exception\QueryNeedsConfirmationException;
use App\Core\Query\Exception\QueryRefusedException;
use App\Core\Query\Exception\QueryTimeoutException;
use App\Core\Schema\Change\Exception\InvalidSchemaChangeException;
use App\Core\Schema\Change\Exception\SchemaChangeFailedException;
use App\Core\Schema\Change\Exception\SchemaChangeNeedsConfirmationException;
use App\Core\Schema\Change\Exception\StaleSchemaException;
use App\Core\Schema\Change\Exception\UnsupportedPartitionChangeException;
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
use App\Security\Exception\AccountAlreadyExistsException;
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
    public function map(\Throwable $error): ?ErrorResponse
    {
        return match (true) {
            $error instanceof InvalidCredentialsException => new ErrorResponse(401, 'invalid_credentials'),
            $error instanceof SecondFactorRequiredException => new ErrorResponse(401, 'second_factor_required'),
            $error instanceof InvalidSecondFactorException => new ErrorResponse(401, 'invalid_second_factor'),
            $error instanceof LockedException, $error instanceof CryptoException => new ErrorResponse(401, 'locked'),
            $error instanceof TooManyAttemptsException => new ErrorResponse(429, 'too_many_attempts', ['retryAfter' => $error->retryAfter], ['Retry-After' => (string) $error->retryAfter]),
            $error instanceof BadRequestException => new ErrorResponse(400, 'bad_request', ['message' => $error->getMessage()]),
            $error instanceof PasswordPolicyException => new ErrorResponse(422, 'weak_password', ['message' => $error->getMessage()]),
            $error instanceof InvalidApiTokenException => new ErrorResponse(401, 'invalid_token'),
            $error instanceof ApiTokenPermissionException => new ErrorResponse(403, 'permission_denied'),
            $error instanceof ForbiddenTargetHostException => new ErrorResponse(403, 'forbidden_target'),
            $error instanceof NotAnAdministratorException => new ErrorResponse(403, 'forbidden'),
            $error instanceof InvalidInvitationTokenException => new ErrorResponse(403, 'invalid_invitation'),
            $error instanceof InvalidUserException,
            $error instanceof InvalidInvitationException,
            $error instanceof InvalidApiTokenDefinitionException,
            $error instanceof InvalidDatabaseConnectionException,
            $error instanceof InvalidConnectionOrderException,
            $error instanceof InvalidConnectionParametersException,
            $error instanceof InvalidKeyException,
            $error instanceof InvalidQueryOptionsException => new ErrorResponse(422, 'invalid_input', ['message' => $error->getMessage()]),
            $error instanceof InvalidRecoveryException => new ErrorResponse(403, 'invalid_recovery'),
            $error instanceof MailDisabledException => new ErrorResponse(409, 'mail_disabled'),
            $error instanceof AccountAlreadyExistsException => new ErrorResponse(409, 'account_exists'),
            $error instanceof TotpAlreadyEnabledException => new ErrorResponse(409, 'totp_already_enabled'),
            $error instanceof TotpNotEnabledException => new ErrorResponse(409, 'totp_not_enabled'),
            $error instanceof ConnectionNameAlreadyUsedException => new ErrorResponse(409, 'connection_name_used'),
            $error instanceof ConnectionNotFoundException, $error instanceof TableNotFoundException, $error instanceof KeyNotFoundException, $error instanceof ApiTokenNotFoundException, $error instanceof InvitationNotFoundException => new ErrorResponse(404, 'not_found'),
            $error instanceof TargetConnectionFailedException => new ErrorResponse(502, 'target_unreachable'),
            $error instanceof UnsupportedEngineException => new ErrorResponse(501, 'unsupported_engine'),
            $error instanceof QueryRefusedException => new ErrorResponse(403, 'query_refused', ['reason' => $error->reason->value]),
            $error instanceof QueryNeedsConfirmationException => new ErrorResponse(409, 'confirmation_required', ['reason' => $error->reason->value]),
            $error instanceof InvalidDataChangeException => new ErrorResponse(422, 'invalid_data_change', ['detail' => $error->getMessage()]),
            $error instanceof DataChangeConflictException => new ErrorResponse(409, 'data_changed', ['statement' => $error->statementIndex, 'matched' => $error->matched]),
            $error instanceof DataChangeNeedsConfirmationException => new ErrorResponse(409, 'confirmation_required', ['reason' => 'delete_rows'] + DataPlanPresenter::plan($error->plan)),
            $error instanceof LockConflictException => new ErrorResponse(409, $error->conflict->code(), ['retryable' => true]),
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
            $error instanceof StaleSchemaException => new ErrorResponse(409, 'schema_changed'),
            $error instanceof SchemaChangeNeedsConfirmationException => new ErrorResponse(409, 'confirmation_required', ['reason' => 'destructive_schema_change'] + ChangePlanPresenter::plan($error->plan)),
            $error instanceof SchemaChangeFailedException => null !== $error->lock
                ? new ErrorResponse(409, $error->lock->code(), ['retryable' => [] === $error->applied, 'applied' => array_map(ChangePlanPresenter::statement(...), $error->applied), 'failed' => ChangePlanPresenter::statement($error->failed)])
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
}
