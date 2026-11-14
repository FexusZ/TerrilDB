<?php

declare(strict_types=1);

namespace App\Core\Diagnostics;

/**
 * L'état d'une source de diagnostic. La raison est un code stable (jamais le message du moteur, qui peut citer un hôte
 * ou un identifiant) et le privilège manquant un mot-clé de droit (`PROCESS`, `pg_read_all_stats`…), jamais du texte libre.
 */
final readonly class DiagnosticStatus
{
    private const CODE_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/D';
    private const PRIVILEGE_PATTERN = '/^[A-Za-z][A-Za-z0-9_. ]{0,63}$/D';

    private function __construct(
        public Availability $availability,
        public ?string $reason,
        public ?string $privilege,
    ) {
        if (null !== $reason && 1 !== preg_match(self::CODE_PATTERN, $reason)) {
            throw new \InvalidArgumentException('A diagnostic reason is a stable code.');
        }
        if (null !== $privilege && 1 !== preg_match(self::PRIVILEGE_PATTERN, $privilege)) {
            throw new \InvalidArgumentException('A privilege is a keyword.');
        }
    }

    public static function available(): self
    {
        return new self(Availability::Available, null, null);
    }

    public static function denied(string $privilege): self
    {
        return new self(Availability::PermissionDenied, 'insufficient_privilege', $privilege);
    }

    public static function disabled(string $reason): self
    {
        return new self(Availability::Disabled, $reason, null);
    }

    /**
     * La vérification de ce thème a échoué pour une cause que les autres états ne couvrent pas. Le code est fixe : le message du
     * moteur (hôte, utilisateur…) n'entre jamais dans un état.
     */
    public static function failed(): self
    {
        return new self(Availability::Error, 'check_failed', null);
    }

    public static function unsupported(string $reason = 'not_supported'): self
    {
        return new self(Availability::Unsupported, $reason, null);
    }

    public function isAvailable(): bool
    {
        return Availability::Available === $this->availability;
    }
}
