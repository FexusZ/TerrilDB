<?php

declare(strict_types=1);

namespace App\Http\Web\Security;

enum LockoutKind: string
{
    /** Une adresse n'a plus le droit d'essayer ce compte pour un temps. */
    case PairBlocked = 'pair_blocked';
    /** Une adresse n'a plus le droit d'essayer aucun compte pour un temps. */
    case AddressBanned = 'address_banned';
}
