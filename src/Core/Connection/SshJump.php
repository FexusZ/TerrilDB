<?php

declare(strict_types=1);

namespace App\Core\Connection;

use App\Core\Connection\Exception\InvalidConnectionParametersException;

/**
 * Rebond par un bastion SSH, authentifié par clé privée (les mots de passe SSH ne sont pas pris en charge). L'empreinte
 * SHA-256 de la clé d'hôte est enregistrée à la première utilisation, après confirmation de l'utilisateur ; absente,
 * le bastion n'a pas encore été reconnu.
 */
final readonly class SshJump implements Transport
{
    private const KEY_MAX_BYTES = 16384;

    public function __construct(
        public string $host,
        public int $port,
        public string $username,
        #[\SensitiveParameter] private string $privateKey,
        public ?string $hostKeyFingerprint = null,
    ) {
        TransportRules::assertHost($host, 'jump');
        TransportRules::assertPort($port, 'jump');
        TransportRules::assertName($username, 'jump username');
        if (\strlen($privateKey) > self::KEY_MAX_BYTES || 1 !== preg_match('/^-----BEGIN [A-Z ]*PRIVATE KEY-----\R.*-----END [A-Z ]*PRIVATE KEY-----\s*$/sD', $privateKey)) {
            throw new InvalidConnectionParametersException('The jump key is not a private key.');
        }
        if (null !== $hostKeyFingerprint && 1 !== preg_match('/^SHA256:[A-Za-z0-9+\/]{43}$/D', $hostKeyFingerprint)) {
            throw new InvalidConnectionParametersException('The host key fingerprint is not valid.');
        }
    }

    public function privateKey(): string
    {
        return $this->privateKey;
    }

    public function toArray(): array
    {
        return [
            'type' => 'ssh',
            'host' => $this->host,
            'port' => $this->port,
            'username' => $this->username,
            'privateKey' => $this->privateKey,
            'hostKeyFingerprint' => $this->hostKeyFingerprint,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['host' => $this->host, 'port' => (string) $this->port, 'username' => $this->username, 'privateKey' => '***', 'hostKeyFingerprint' => $this->hostKeyFingerprint ?? ''];
    }

    /**
     * @return never
     */
    public function __serialize(): array
    {
        throw new \LogicException('A jump must not be serialized.');
    }
}
