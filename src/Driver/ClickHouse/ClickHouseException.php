<?php

declare(strict_types=1);

namespace App\Driver\ClickHouse;

/**
 * Une requête HTTP vers ClickHouse a échoué. `engineCode` est le code d'erreur du moteur quand il a répondu
 * (null si le serveur n'a pas été joint). Le message ne contient jamais l'adresse ni les identifiants.
 */
final class ClickHouseException extends \RuntimeException
{
    public function __construct(string $message, public readonly ?int $engineCode = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, previous: $previous);
    }
}
