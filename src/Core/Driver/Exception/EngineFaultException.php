<?php

declare(strict_types=1);

namespace App\Core\Driver\Exception;

use App\Core\Driver\EngineFault;

/**
 * Le moteur a refusé l'instruction pour une raison courante (serveur plein, droit refusé, doublon). Le message du moteur reste dans
 * l'exception précédente : il peut citer une valeur de l'utilisateur et n'est jamais montré.
 */
final class EngineFaultException extends \RuntimeException
{
    /** `engineMessage` : le texte du moteur, gardé POUR le mode sans détail (réponse générique d'origine), jamais montré avec le réglage. */
    public function __construct(public readonly EngineFault $fault, ?\Throwable $previous = null, public readonly string $engineMessage = '')
    {
        parent::__construct(\sprintf('The engine reported a known error (%s).', $fault->code()), 0, $previous);
    }
}
