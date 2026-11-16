<?php

declare(strict_types=1);

namespace App\Core\Guard\Exception;

/**
 * Le script ne peut pas être découpé avec certitude : on ne sait plus ce que le moteur lirait, donc rien ne part.
 */
final class UnsplittableScriptException extends \RuntimeException
{
    private function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }

    public static function incomplete(): self
    {
        return new self('A string, comment or quoted name is never closed.', 'incomplete');
    }

    public static function separatorInExecutableComment(): self
    {
        return new self('A semicolon sits inside a comment that the engine executes.', 'separator_in_executable_comment');
    }

    /**
     * DELIMITER est une commande du client mysql, pas du moteur : la suivre demanderait de redécouper le texte à la
     * main, et un découpage faux enverrait des morceaux de corps de procédure. On refuse plutôt que de deviner.
     */
    public static function delimiterDirective(): self
    {
        return new self('The client directive DELIMITER is not supported: send the body of a procedure or trigger as one statement, without it.', 'delimiter_directive');
    }
}
