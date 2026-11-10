<?php

declare(strict_types=1);

namespace App\Core\Guard\Exception;

/**
 * Le script ne peut pas être découpé avec certitude : on ne sait plus ce que le moteur lirait, donc rien ne part.
 */
final class UnsplittableScriptException extends \RuntimeException
{
    public static function incomplete(): self
    {
        return new self('A string, comment or quoted name is never closed.');
    }

    public static function separatorInExecutableComment(): self
    {
        return new self('A semicolon sits inside a comment that the engine executes.');
    }
}
