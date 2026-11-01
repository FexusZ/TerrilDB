<?php

declare(strict_types=1);

namespace App\Driver\Redis;

/**
 * Redis a répondu par une erreur à une commande (WRONGTYPE, NOPERM, syntaxe…). Le message est celui du serveur, sans son
 * code d'erreur en tête ; il concerne la commande de l'utilisateur et ne contient ni adresse ni identifiant.
 */
final class RedisReplyException extends \RuntimeException
{
    public function __construct(public readonly string $replyCode, string $message)
    {
        parent::__construct($message);
    }

    public static function fromReply(string $reply): self
    {
        $parts = explode(' ', $reply, 2);
        $code = $parts[0];
        $isCode = '' !== $code && 1 === preg_match('/^[A-Z][A-Z0-9_-]*$/D', $code);

        return new self($isCode ? $code : 'ERR', $isCode && isset($parts[1]) ? $parts[1] : $reply);
    }
}
