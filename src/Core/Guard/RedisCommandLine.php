<?php

declare(strict_types=1);

namespace App\Core\Guard;

/**
 * Découpe un texte de console Redis en commandes (une par ligne non vide) et en arguments, comme le fait redis-cli :
 * guillemets doubles avec échappements (`\n`, `\x41`, `\"`…), guillemets simples (seul `\'` y est échappé), espaces
 * pour séparer. Ce sont ces arguments, et non le texte, qui partent ensuite vers le serveur : le garde juge exactement
 * ce qui sera exécuté. Une guillemet collée à du texte n'est pas un argument valide (le texte est alors incomplet).
 */
final readonly class RedisCommandLine
{
    /**
     * @param list<list<string>> $commands
     */
    private function __construct(public array $commands, public bool $complete)
    {
    }

    public static function parse(string $text): self
    {
        $commands = [];
        $arguments = [];
        $length = \strlen($text);
        $i = 0;

        while ($i < $length) {
            $char = $text[$i];
            if ("\n" === $char) {
                if ([] !== $arguments) {
                    $commands[] = $arguments;
                    $arguments = [];
                }
                ++$i;
            } elseif (' ' === $char || "\t" === $char || "\r" === $char) {
                ++$i;
            } elseif ('"' === $char || "'" === $char) {
                $argument = '"' === $char ? self::doubleQuoted($text, $i) : self::singleQuoted($text, $i);
                if (null === $argument) {
                    return new self($commands, false);
                }
                $arguments[] = $argument[0];
                $i = $argument[1];
                $next = $text[$i] ?? '';
                if ('' !== $next && !ctype_space($next)) {
                    return new self($commands, false);
                }
            } else {
                $start = $i;
                while ($i < $length && !ctype_space($text[$i])) {
                    if ('"' === $text[$i] || "'" === $text[$i]) {
                        return new self($commands, false);
                    }
                    ++$i;
                }
                $arguments[] = substr($text, $start, $i - $start);
            }
        }
        if ([] !== $arguments) {
            $commands[] = $arguments;
        }

        return new self($commands, true);
    }

    /**
     * @return array{string, int}|null l'argument et la position après le guillemet fermant, null si non refermé
     */
    private static function doubleQuoted(string $text, int $from): ?array
    {
        $value = '';
        $length = \strlen($text);
        for ($i = $from + 1; $i < $length; ++$i) {
            $char = $text[$i];
            if ('\\' === $char && $i + 1 < $length) {
                $next = $text[$i + 1];
                if ('x' === $next && 1 === preg_match('/\G[0-9a-fA-F]{2}/', $text, $hex, 0, $i + 2)) {
                    $value .= \chr(((int) hexdec($hex[0])) & 0xFF);
                    $i += 3;
                } else {
                    $value .= match ($next) {
                        'n' => "\n",
                        'r' => "\r",
                        't' => "\t",
                        'b' => "\x08",
                        'a' => "\x07",
                        default => $next,
                    };
                    ++$i;
                }
            } elseif ('"' === $char) {
                return [$value, $i + 1];
            } else {
                $value .= $char;
            }
        }

        return null;
    }

    /**
     * @return array{string, int}|null
     */
    private static function singleQuoted(string $text, int $from): ?array
    {
        $value = '';
        $length = \strlen($text);
        for ($i = $from + 1; $i < $length; ++$i) {
            $char = $text[$i];
            if ('\\' === $char && "'" === ($text[$i + 1] ?? '')) {
                $value .= "'";
                ++$i;
            } elseif ("'" === $char) {
                return [$value, $i + 1];
            } else {
                $value .= $char;
            }
        }

        return null;
    }
}
