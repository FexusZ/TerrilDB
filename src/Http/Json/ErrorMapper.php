<?php

declare(strict_types=1);

namespace App\Http\Json;

use App\ApiToken\Exception\ApiTokenNotFoundException;
use App\ApiToken\Exception\InvalidApiTokenException;
use App\Connection\Exception\ConnectionNameAlreadyUsedException;
use App\Connection\Exception\ConnectionNotFoundException;
use App\Core\Connection\Exception\InvalidConnectionParametersException;
use App\Core\Driver\Exception\TargetConnectionFailedException;
use App\Core\Driver\Exception\UnsupportedEngineException;
use App\Core\Optimizer\Exception\UnreadablePlanException;
use App\Core\Query\Exception\InvalidQueryOptionsException;
use App\Core\Query\Exception\QueryFailedException;
use App\Core\Query\Exception\QueryNeedsConfirmationException;
use App\Core\Query\Exception\QueryRefusedException;
use App\Core\Query\Exception\QueryTimeoutException;
use App\Core\Schema\Exception\TableNotFoundException;
use App\Crypto\Exception\CryptoException;
use App\Entity\Exception\InvalidApiTokenDefinitionException;
use App\Entity\Exception\InvalidDatabaseConnectionException;
use App\Entity\Exception\InvalidInvitationException;
use App\Entity\Exception\InvalidUserException;
use App\Invitation\Exception\InvalidInvitationTokenException;
use App\Invitation\Exception\InvitationNotFoundException;
use App\Invitation\Exception\NotAnAdministratorException;
use App\Security\Exception\AccountAlreadyExistsException;
use App\Security\Exception\InvalidCredentialsException;
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
            $error instanceof NotAnAdministratorException => new ErrorResponse(403, 'forbidden'),
            $error instanceof InvalidInvitationTokenException => new ErrorResponse(403, 'invalid_invitation'),
            $error instanceof InvalidUserException,
            $error instanceof InvalidInvitationException,
            $error instanceof InvalidApiTokenDefinitionException,
            $error instanceof InvalidDatabaseConnectionException,
            $error instanceof InvalidConnectionParametersException,
            $error instanceof InvalidQueryOptionsException => new ErrorResponse(422, 'invalid_input', ['message' => $error->getMessage()]),
            $error instanceof AccountAlreadyExistsException => new ErrorResponse(409, 'account_exists'),
            $error instanceof TotpAlreadyEnabledException => new ErrorResponse(409, 'totp_already_enabled'),
            $error instanceof TotpNotEnabledException => new ErrorResponse(409, 'totp_not_enabled'),
            $error instanceof ConnectionNameAlreadyUsedException => new ErrorResponse(409, 'connection_name_used'),
            $error instanceof ConnectionNotFoundException, $error instanceof TableNotFoundException, $error instanceof ApiTokenNotFoundException, $error instanceof InvitationNotFoundException => new ErrorResponse(404, 'not_found'),
            $error instanceof TargetConnectionFailedException => new ErrorResponse(502, 'target_unreachable'),
            $error instanceof UnsupportedEngineException => new ErrorResponse(501, 'unsupported_engine'),
            $error instanceof QueryRefusedException => new ErrorResponse(403, 'query_refused', ['reason' => $error->reason->value]),
            $error instanceof QueryNeedsConfirmationException => new ErrorResponse(409, 'confirmation_required', ['reason' => $error->reason->value]),
            $error instanceof UnreadablePlanException => new ErrorResponse(502, 'unreadable_plan'),
            $error instanceof QueryTimeoutException => new ErrorResponse(408, 'query_timeout'),
            $error instanceof QueryFailedException => new ErrorResponse(400, 'query_failed', ['message' => $error->getMessage()]),
            default => null,
        };
    }
}
