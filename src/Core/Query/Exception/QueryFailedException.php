<?php

declare(strict_types=1);

namespace App\Core\Query\Exception;

/**
 * Le moteur a refusé ou fait échouer l'instruction (syntaxe, contrainte, transaction en lecture seule…).
 * Le message est celui du moteur : il concerne la requête de l'utilisateur et ne contient aucun identifiant
 * de connexion.
 */
final class QueryFailedException extends \RuntimeException
{
}
