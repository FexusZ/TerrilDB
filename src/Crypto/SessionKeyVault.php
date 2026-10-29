<?php

declare(strict_types=1);

namespace App\Crypto;

/**
 * Garde, le temps d'une session, la clé dérivée du mot de passe de l'utilisateur (déverrouillée au
 * login). Le coffre est propriétaire des clés qu'on lui confie : il les efface quand il les oublie.
 *
 * Le stockage réel (mémoire partagée, cache chiffré…) n'est pas encore tranché : il se branche
 * derrière cette interface sans toucher au reste du code.
 */
interface SessionKeyVault
{
    public function put(string $sessionId, DerivedKey $key): void;

    /**
     * @return DerivedKey|null null si la session est inconnue ou si sa clé a été effacée
     */
    public function get(string $sessionId): ?DerivedKey;

    public function forget(string $sessionId): void;
}
