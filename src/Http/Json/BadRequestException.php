<?php

declare(strict_types=1);

namespace App\Http\Json;

/**
 * Requête mal formée (JSON illisible, champ manquant ou d'un mauvais type). Le message nomme le champ,
 * jamais sa valeur : un mot de passe mal typé ne doit pas finir dans une réponse ou un journal.
 */
final class BadRequestException extends \RuntimeException
{
}
