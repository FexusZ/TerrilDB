<?php

declare(strict_types=1);

namespace App\ApiToken;

use App\ApiToken\Exception\InvalidApiTokenException;
use Symfony\Component\Uid\Uuid;

/**
 * Un jeton d'API tel que le client le détient : `tdb_<identifiant>_<secret>`. L'identifiant (un UUID sans
 * tirets) retrouve la ligne ; le secret, 256 bits aléatoires, n'est jamais stocké : le serveur ne garde que
 * son empreinte (pour reconnaître le jeton) et une copie de la clé de l'utilisateur enveloppée par ce secret
 * (pour qu'une requête de l'API puisse déchiffrer les connexions). Sans le jeton, ni l'un ni l'autre ne servent.
 */
final readonly class ApiTokenSecret
{
    private const PATTERN = '/^tdb_([0-9a-f]{32})_([A-Za-z0-9_-]{43})$/D';

    private function __construct(
        public Uuid $id,
        #[\SensitiveParameter] private string $secret,
    ) {
    }

    public static function generate(Uuid $id): self
    {
        return new self($id, sodium_bin2base64(random_bytes(32), \SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING));
    }

    public static function parse(#[\SensitiveParameter] string $token): self
    {
        if (1 !== preg_match(self::PATTERN, $token, $parts)) {
            throw InvalidApiTokenException::create();
        }

        $hex = $parts[1];
        $uuid = \sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20));
        if (!Uuid::isValid($uuid)) {
            throw InvalidApiTokenException::create();
        }

        return new self(Uuid::fromString($uuid), $parts[2]);
    }

    public function secret(): string
    {
        return $this->secret;
    }

    public function toString(): string
    {
        return \sprintf('tdb_%s_%s', str_replace('-', '', $this->id->toRfc4122()), $this->secret);
    }

    /**
     * Empreinte qui permet de reconnaître le jeton sans pouvoir le reconstituer.
     */
    public function verifier(): string
    {
        return hash('sha256', 'terrildb-api-token'."\0".$this->secret);
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['id' => $this->id->toRfc4122(), 'secret' => '***'];
    }

    /**
     * @return never
     */
    public function __serialize(): array
    {
        throw new \LogicException('An API token must not be serialized.');
    }
}
