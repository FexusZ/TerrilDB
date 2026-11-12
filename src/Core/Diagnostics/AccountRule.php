<?php

declare(strict_types=1);

namespace App\Core\Diagnostics;

/** Les règles d'audit de sécurité des comptes ; la valeur est le code stable que l'interface traduit. */
enum AccountRule: string
{
    case AnonymousAccount = 'anonymous_account';
    case WildcardHost = 'wildcard_host';
    case SameNameManyHosts = 'same_name_many_hosts';
    case NoPassword = 'no_password';
    case LegacyPlugin = 'legacy_plugin';
    case DisabledPlugin = 'disabled_plugin';
    case PasswordExpired = 'password_expired';
    case DangerousPrivilege = 'dangerous_privilege';
    case AllPrivileges = 'all_privileges';
    case GrantOption = 'grant_option';
    case RoleNeverActive = 'role_never_active';
    case NoTlsRequirement = 'no_tls_requirement';
    case OpenTestDatabase = 'open_test_database';
    /** La lecture est coupée : l'audit n'a pas tout vu, « rien à signaler » ne serait pas vrai. */
    case IncompleteCheck = 'incomplete_check';
}
