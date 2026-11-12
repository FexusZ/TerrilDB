<?php

declare(strict_types=1);

namespace App\Core\Connection\Tunnel;

use App\Core\Connection\Exception\ForbiddenBastionException;
use App\Core\Connection\SshJump;

/**
 * Les bastions que l'administrateur autorise (`TERRILDB_SSH_ALLOWED_BASTIONS` : « hôte » ou « hôte:port », séparés par
 * des virgules, port 22 par défaut). Vide, la liste refuse tout : sans déclaration explicite, aucun tunnel ne sort.
 * Comparaison exacte (casse et point final ignorés), ni joker ni sous-chaîne.
 */
final readonly class BastionAllowList
{
    /**
     * @param list<string> $entries hôte:port normalisés
     */
    private function __construct(private array $entries)
    {
    }

    public static function fromString(string $configured): self
    {
        $entries = [];
        foreach (explode(',', $configured) as $item) {
            $item = trim($item);
            if ('' === $item) {
                continue;
            }
            $host = $item;
            $port = 22;
            if (1 === preg_match('/^(.+):(\d{1,5})$/D', $item, $match)) {
                $host = $match[1];
                $port = (int) $match[2];
            }
            $entries[] = self::key($host, $port);
        }

        return new self($entries);
    }

    /**
     * @throws ForbiddenBastionException
     */
    public function assertAllowed(SshJump $jump): void
    {
        if (!\in_array(self::key($jump->host, $jump->port), $this->entries, true)) {
            throw ForbiddenBastionException::create();
        }
    }

    private static function key(string $host, int $port): string
    {
        return strtolower(trim($host, "[] .\t")).':'.$port;
    }
}
