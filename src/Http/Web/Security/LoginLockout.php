<?php

declare(strict_types=1);

namespace App\Http\Web\Security;

use App\Http\Json\TooManyAttemptsException;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;

/**
 * Blocage à paliers des échecs de connexion, par COUPLE adresse + compte : cinq échecs de suite bloquent ce couple
 * (15 min, puis 1 h, 3 h, 6 h, 12 h à chaque nouveau blocage). Le compte lui-même n'est jamais verrouillé pour tout le
 * monde : un tiers ne peut donc pas empêcher son propriétaire de se connecter depuis une autre adresse. Une adresse
 * dont les blocages touchent trois comptes différents est bannie (24 h, puis 3 jours, puis 7 jours).
 *
 * Un compte inconnu se compte comme un compte connu (la clé est le texte saisi) : rien ne révèle lesquels existent.
 * Un palier s'oublie après un jour sans nouveau blocage ; une connexion réussie remet à zéro les échecs de suite.
 * L'état vit dans le cache de l'application ; deux échecs simultanés peuvent n'en compter qu'un (course tolérée).
 */
final readonly class LoginLockout
{
    public const PAIR_FAILURES = 5;
    /** Durées successives du blocage d'un couple, en secondes. */
    public const PAIR_BLOCKS = [900, 3600, 10800, 21600, 43200];
    public const ADDRESS_ACCOUNTS = 3;
    /** Durées successives du bannissement d'une adresse, en secondes. */
    public const ADDRESS_BANS = [86400, 259200, 604800];
    /** Temps sans nouveau blocage après lequel le palier retombe au premier. */
    public const MEMORY = 86400;

    public function __construct(
        private CacheItemPoolInterface $pool,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws TooManyAttemptsException
     */
    public function check(?string $ip, string $login): void
    {
        $now = $this->now();
        $address = $this->load(self::addressKey($ip));
        $pair = $this->load(self::pairKey($ip, $login));

        $until = max($address['until'], $pair['until']);
        if ($until > $now) {
            throw new TooManyAttemptsException(max(1, $until - $now));
        }
    }

    /**
     * Note un échec. Rend ce qu'il déclenche, le cas échéant (un bannissement l'emporte sur un blocage).
     */
    public function failed(?string $ip, string $login): ?LockoutOutcome
    {
        $now = $this->now();
        $pairKey = self::pairKey($ip, $login);
        $pair = $this->load($pairKey);
        ++$pair['failures'];

        if ($pair['failures'] < self::PAIR_FAILURES) {
            $this->save($pairKey, $pair, self::MEMORY);

            return null;
        }

        [$pair['level'], $seconds] = self::next($pair, self::PAIR_BLOCKS, $now);
        $pair['failures'] = 0;
        $pair['until'] = $now + $seconds;
        $this->save($pairKey, $pair, $seconds + self::MEMORY);

        $addressKey = self::addressKey($ip);
        $address = $this->load($addressKey);
        if ($now - $address['since'] > self::MEMORY) {
            $address['accounts'] = [];
            $address['since'] = $now;
        }
        $account = self::accountKey($login);
        if (!\in_array($account, $address['accounts'], true)) {
            $address['accounts'][] = $account;
        }

        if (\count($address['accounts']) < self::ADDRESS_ACCOUNTS) {
            $this->save($addressKey, $address, self::MEMORY * 2);

            return new LockoutOutcome(LockoutKind::PairBlocked, $seconds);
        }

        [$address['level'], $ban] = self::next($address, self::ADDRESS_BANS, $now);
        $address['until'] = $now + $ban;
        $address['accounts'] = [];
        $address['since'] = $now;
        $this->save($addressKey, $address, $ban + self::MEMORY);

        return new LockoutOutcome(LockoutKind::AddressBanned, $ban);
    }

    public function succeeded(?string $ip, string $login): void
    {
        $key = self::pairKey($ip, $login);
        $pair = $this->load($key);
        if (0 !== $pair['failures']) {
            $pair['failures'] = 0;
            $this->save($key, $pair, self::MEMORY);
        }
    }

    /**
     * Le palier à appliquer : le suivant si le dernier blocage est récent, le premier sinon.
     *
     * @param array{level: int, until: int} $state
     * @param non-empty-list<int>           $durations
     *
     * @return array{int, int} [nouveau palier, durée en secondes]
     */
    private static function next(array $state, array $durations, int $now): array
    {
        $level = $now < $state['until'] + self::MEMORY ? $state['level'] : 0;

        return [$level + 1, $durations[min($level, \count($durations) - 1)]];
    }

    /**
     * @return array{failures: int, level: int, until: int, accounts: list<string>, since: int}
     */
    private function load(string $key): array
    {
        $state = ['failures' => 0, 'level' => 0, 'until' => 0, 'accounts' => [], 'since' => 0];
        $stored = $this->pool->getItem($key)->get();
        if (!\is_array($stored)) {
            return $state;
        }
        foreach (['failures', 'level', 'until', 'since'] as $field) {
            if (\is_int($stored[$field] ?? null)) {
                $state[$field] = $stored[$field];
            }
        }
        if (\is_array($stored['accounts'] ?? null)) {
            $state['accounts'] = array_values(array_filter($stored['accounts'], \is_string(...)));
        }

        return $state;
    }

    /**
     * @param array{failures: int, level: int, until: int, accounts: list<string>, since: int} $state
     */
    private function save(string $key, array $state, int $ttl): void
    {
        $item = $this->pool->getItem($key);
        $item->set($state)->expiresAfter($ttl);
        $this->pool->save($item);
    }

    private function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }

    private static function accountKey(string $login): string
    {
        return hash('sha256', mb_strtolower(trim($login)));
    }

    private static function addressKey(?string $ip): string
    {
        return 'lockout.address.'.hash('sha256', $ip ?? 'unknown');
    }

    private static function pairKey(?string $ip, string $login): string
    {
        return 'lockout.pair.'.hash('sha256', ($ip ?? 'unknown').'|'.self::accountKey($login));
    }
}
