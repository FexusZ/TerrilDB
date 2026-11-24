<?php

declare(strict_types=1);

namespace App\Core\Connection;

use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;

/**
 * Les réseaux IPv4 reliés EN DIRECT au serveur (table de routage du noyau : une route sans passerelle qui n'est ni la route par défaut ni
 * la boucle locale). Sert à une seule chose : reconnaître qu'une adresse de cible tombe dans un réseau local du conteneur (un pont
 * Docker, par exemple), auquel cas le trafic n'en sort pas et la cible paraît « en panne ». Pure : le texte de la table est donné.
 */
#[Autoconfigure(constructor: 'ofHost', lazy: true)]
final readonly class LocalNetworks
{
    private const ROUTE_TABLE = '/proc/net/route';
    private const FLAG_UP = 0x1;
    private const FLAG_GATEWAY = 0x2;

    /**
     * @param list<array{network: int, mask: int}> $networks
     */
    private function __construct(private array $networks)
    {
    }

    /** Le serveur lui-même ; table illisible = aucun réseau connu, jamais une erreur. */
    public static function ofHost(): self
    {
        $content = @file_get_contents(self::ROUTE_TABLE);

        return self::fromRoutes(false === $content ? '' : $content);
    }

    public static function fromRoutes(string $table): self
    {
        $networks = [];
        foreach (explode("\n", $table) as $line) {
            $fields = preg_split('/\s+/', trim($line));
            if (false === $fields || \count($fields) < 8 || !ctype_xdigit($fields[1]) || !ctype_xdigit($fields[2]) || !ctype_xdigit($fields[3]) || !ctype_xdigit($fields[7])) {
                continue;
            }
            $flags = (int) hexdec($fields[3]);
            if (0 === ($flags & self::FLAG_UP) || 0 !== ($flags & self::FLAG_GATEWAY) || 0 !== (int) hexdec($fields[2])) {
                continue;
            }
            // Le noyau écrit les adresses en hexadécimal, octets dans l'ordre de la machine (petit boutiste).
            $network = self::fromKernel($fields[1]);
            $mask = self::fromKernel($fields[7]);
            if (0 === $mask || 0 === $network || 127 === ($network >> 24)) {
                continue;
            }
            $networks[] = ['network' => $network & $mask, 'mask' => $mask];
        }

        return new self($networks);
    }

    /** @return list<string> */
    public function cidrs(): array
    {
        return array_map(static fn (array $n): string => long2ip($n['network']).'/'.(32 - (int) log($n['mask'] & -$n['mask'], 2)), $this->networks);
    }

    public function covers(string $address): bool
    {
        if (false === filter_var($address, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4)) {
            return false;
        }
        $ip = (int) ip2long($address);
        foreach ($this->networks as $network) {
            if (($ip & $network['mask']) === $network['network']) {
                return true;
            }
        }

        return false;
    }

    private static function fromKernel(string $hex): int
    {
        $value = (int) hexdec(str_pad($hex, 8, '0', \STR_PAD_LEFT));

        return (($value & 0xFF) << 24) | (($value & 0xFF00) << 8) | (($value >> 8) & 0xFF00) | (($value >> 24) & 0xFF);
    }
}
