<?php

declare(strict_types=1);

namespace App\Core\Connection\Tunnel;

/**
 * Le tunnel n'a pas pu s'ouvrir. Seule la raison, un code stable, est exposée : ni la sortie d'`ssh`, ni la clé, ni
 * ce que le bastion a répondu.
 */
final class TunnelFailedException extends \RuntimeException
{
    public const HOST_KEY_UNCONFIRMED = 'host_key_unconfirmed';
    public const HOST_KEY_CHANGED = 'host_key_changed';
    public const UNAVAILABLE = 'unavailable';

    public function __construct(public readonly string $reason = self::UNAVAILABLE)
    {
        parent::__construct('The tunnel could not be opened.');
    }
}
