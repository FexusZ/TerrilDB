<?php

declare(strict_types=1);

namespace App\Core\Connection;

use App\Core\Connection\Exception\InvalidConnectionParametersException;

/**
 * Proxy SOCKS5, avec ou sans identifiants (RFC 1929 : 255 octets au plus chacun, les deux ou aucun).
 */
final readonly class Socks5Proxy implements Transport
{
    private const CREDENTIAL_MAX_BYTES = 255;

    public function __construct(
        public string $host,
        public int $port = 1080,
        public ?string $username = null,
        #[\SensitiveParameter] private ?string $password = null,
    ) {
        TransportRules::assertHost($host, 'proxy');
        TransportRules::assertPort($port, 'proxy');
        if ((null === $username) !== (null === $password)) {
            throw new InvalidConnectionParametersException('The proxy needs both a username and a password, or neither.');
        }
        if (null !== $username && null !== $password) {
            TransportRules::assertName($username, 'proxy username', self::CREDENTIAL_MAX_BYTES);
            if (\strlen($password) > self::CREDENTIAL_MAX_BYTES || !mb_check_encoding($password, 'UTF-8')) {
                throw new InvalidConnectionParametersException('The proxy password is not valid.');
            }
        }
    }

    public function password(): ?string
    {
        return $this->password;
    }

    public function toArray(): array
    {
        return ['type' => 'socks5', 'host' => $this->host, 'port' => $this->port, 'username' => $this->username, 'password' => $this->password];
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['host' => $this->host, 'port' => (string) $this->port, 'username' => $this->username ?? '', 'password' => null === $this->password ? '' : '***'];
    }

    /**
     * @return never
     */
    public function __serialize(): array
    {
        throw new \LogicException('A proxy must not be serialized.');
    }
}
