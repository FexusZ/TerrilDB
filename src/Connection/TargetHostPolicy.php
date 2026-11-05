<?php

declare(strict_types=1);

namespace App\Connection;

use App\Connection\Exception\ForbiddenTargetHostException;
use App\Core\Connection\ConnectionParameters;
use App\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Seul un administrateur peut viser le serveur de TerrilDB lui-même : la boucle locale, les adresses de lien local
 * (dont les services de métadonnées des fournisseurs de cloud), l'adresse non spécifiée et la base interne de
 * l'application. Un membre garde la main sur toute autre machine, réseaux privés compris.
 *
 * Le nom est résolu une seule fois par création, modification, essai ou ouverture, et l'adresse contrôlée est celle que
 * le driver reçoit (voir `pinnedAddress`) : une réponse DNS qui change ensuite n'a plus d'effet. Les droits du compte
 * de la base cible et le pare-feu du serveur restent le dernier rempart.
 */
final readonly class TargetHostPolicy
{
    public function __construct(
        private HostResolver $resolver,
        #[Autowire('%env(key:host:url:DATABASE_URL)%')] private ?string $internalDatabaseHost,
    ) {
    }

    /**
     * @throws ForbiddenTargetHostException
     */
    public function assertAllowed(User $user, ConnectionParameters $parameters): void
    {
        $this->pinnedAddress($user, $parameters);
    }

    /**
     * Contrôle l'hôte (sauf pour un administrateur) et rend l'adresse à joindre, résolue UNE seule fois : c'est celle-là
     * que le driver doit recevoir, pas le nom, sans quoi une réponse DNS qui change entre ici et la connexion contournerait
     * le contrôle. IPv4 d'abord (la plus courante) ; nul si le nom ne se résout pas (la connexion échouera d'elle-même).
     *
     * @throws ForbiddenTargetHostException
     */
    public function pinnedAddress(User $user, ConnectionParameters $parameters): ?string
    {
        $host = strtolower(trim($parameters->host, "[] .\t"));
        if (!$user->isAdmin() && ('localhost' === $host || str_ends_with($host, '.localhost'))) {
            throw ForbiddenTargetHostException::create();
        }

        $addresses = $this->resolver->resolve($host);
        if (!$user->isAdmin()) {
            $internal = [];
            if (null !== $this->internalDatabaseHost && '' !== $this->internalDatabaseHost) {
                $internal = array_filter(array_map(self::normalize(...), $this->resolver->resolve($this->internalDatabaseHost)));
            }
            foreach ($addresses as $address) {
                $normalized = self::normalize($address);
                if (null === $normalized) {
                    continue;
                }
                if (self::isTheMachineItself($normalized) || \in_array($normalized, $internal, true)) {
                    throw ForbiddenTargetHostException::create();
                }
            }
        }

        $ipv4 = array_values(array_filter($addresses, static fn (string $address): bool => false !== filter_var($address, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4)));
        $ipv6 = array_values(array_filter($addresses, static fn (string $address): bool => false !== filter_var($address, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV6)));

        return [...$ipv4, ...$ipv6][0] ?? null;
    }

    /**
     * L'adresse sous sa forme canonique, une adresse IPv6 « mappée » ramenée à son IPv4.
     */
    private static function normalize(string $address): ?string
    {
        $packed = @inet_pton($address);
        if (false === $packed) {
            return null;
        }
        if (16 === \strlen($packed) && str_starts_with($packed, str_repeat("\0", 10)."\xff\xff")) {
            $packed = substr($packed, 12);
        }

        $text = inet_ntop($packed);

        return false === $text ? null : $text;
    }

    private static function isTheMachineItself(string $normalized): bool
    {
        $packed = inet_pton($normalized);
        if (false === $packed) {
            return false;
        }
        if (4 === \strlen($packed)) {
            $first = \ord($packed[0]);

            return 127 === $first || 0 === $first || (169 === $first && 254 === \ord($packed[1]));
        }

        return $packed === inet_pton('::1') || $packed === inet_pton('::') || (0xFE === \ord($packed[0]) && 0x80 === (\ord($packed[1]) & 0xC0));
    }
}
