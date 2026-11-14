<?php

declare(strict_types=1);

namespace App\Audit;

/**
 * Ce qui est journalisé. Chaque valeur est « catégorie.verbe » : la catégorie sert de filtre.
 */
enum AuditAction: string
{
    case AccountRegistered = 'account.registered';
    case LoginSuccess = 'login.success';
    case LoginFailure = 'login.failure';
    case LoginThrottled = 'login.throttled';
    case LogoutDone = 'logout.done';
    case PasswordChanged = 'password.changed';
    case PasswordChangeFailed = 'password.failed';
    case TotpEnabled = 'totp.enabled';
    case TotpDisabled = 'totp.disabled';
    case RecoveryRegenerated = 'recovery.regenerated';
    case RecoveryUsed = 'recovery.used';
    case PhraseCreated = 'phrase.created';
    case PhraseRemoved = 'phrase.removed';
    case PhraseUsed = 'phrase.used';
    case PhraseFailed = 'phrase.failed';
    case ConnectionCreated = 'connection.created';
    case ConnectionUpdated = 'connection.updated';
    case ConnectionDeleted = 'connection.deleted';
    case TokenCreated = 'token.created';
    case TokenRefreshed = 'token.refreshed';
    case TokenRevoked = 'token.revoked';
    case InvitationCreated = 'invitation.created';
    case InvitationRevoked = 'invitation.revoked';
    case QueryExecuted = 'query.executed';
    case QueryRefused = 'query.refused';
    case QueryFailed = 'query.failed';
    /** Une écriture jugée par `prepare`, sans exécution : le jeton de confirmation n'est jamais journalisé. */
    case QueryPrepared = 'query.prepared';
    case TargetForbidden = 'target.forbidden';
    case SchemaChanged = 'schema.changed';
    case SchemaChangeFailed = 'schema.failed';
    case DataChanged = 'data.changed';
    case DataChangeFailed = 'data.failed';
    case DiagnosticRead = 'diagnostic.read';

    public function category(): string
    {
        return explode('.', $this->value)[0];
    }
}
