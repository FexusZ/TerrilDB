<?php

declare(strict_types=1);

namespace App\Core\Diagnostics;

/**
 * Une variable de configuration du serveur, telle que le moteur la rapporte. La valeur d'un nom qui évoque un secret n'entre
 * jamais ici (ni elle ni son défaut) : c'est le modèle qui le garantit, quel que soit le moteur.
 */
final readonly class ServerVariable
{
    public const MAX_VALUE_LENGTH = 500;

    public ?string $value;
    public ?string $default;
    public bool $masked;

    public function __construct(
        public string $name,
        ?string $value,
        ?string $default,
        /** Diffère du défaut du moteur (ou, quand il ne donne pas de défaut, a une autre origine que la compilation). */
        public bool $changed,
        /** D'où vient la valeur quand le moteur le dit (fichier, ligne de commande, modifiée à chaud…). */
        public ?string $source,
        public ?RestartNeed $restart,
        /** `global`, `session` ou `server` quand le moteur le distingue. */
        public ?string $scope,
        /** Unité de la valeur et du défaut quand le moteur en donne une (`8kB`, `ms`). */
        public ?string $unit = null,
    ) {
        $this->masked = SecretVariables::matches($name);
        $this->value = $this->masked ? null : self::shorten($value);
        $this->default = $this->masked ? null : self::shorten($default);
    }

    private static function shorten(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }
        $value = mb_scrub($value, 'UTF-8');

        return mb_strlen($value) > self::MAX_VALUE_LENGTH ? mb_substr($value, 0, self::MAX_VALUE_LENGTH - 1).'…' : $value;
    }
}
