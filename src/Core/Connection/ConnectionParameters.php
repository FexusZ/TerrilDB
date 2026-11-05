<?php

declare(strict_types=1);

namespace App\Core\Connection;

use App\Core\Connection\Exception\InvalidConnectionParametersException;

/**
 * Ce qu'il faut pour joindre une base cible : c'est l'ensemble de ces paramètres (pas seulement le mot de passe)
 * qui est chiffré avec la clé de l'utilisateur. Le moteur reste en clair sur la connexion, il sert à la lister.
 *
 * Le mot de passe est privé et masqué des dumps ; l'objet refuse d'être sérialisé pour ne pas finir en session
 * ou en cache. Le JSON est versionné (« v ») pour pouvoir ajouter des options sans casser l'existant.
 */
final readonly class ConnectionParameters
{
    private const FORMAT_VERSION = 1;
    private const HOST_MAX_LENGTH = 255;
    private const NAME_MAX_LENGTH = 128;
    private const PASSWORD_MAX_LENGTH = 1024;

    public function __construct(
        public string $host,
        public int $port,
        public string $database,
        public string $username,
        #[\SensitiveParameter] private string $password,
        private ?string $pinnedAddress = null,
    ) {
        if ('' === $host || \strlen($host) > self::HOST_MAX_LENGTH || 1 !== preg_match('/^[^\s\p{Cc}]+$/uD', $host)) {
            throw new InvalidConnectionParametersException('The host is not valid.');
        }
        if ($port < 1 || $port > 65535) {
            throw new InvalidConnectionParametersException('The port must be between 1 and 65535.');
        }
        self::assertName($database, 'database');
        self::assertName($username, 'username');
        if (\strlen($password) > self::PASSWORD_MAX_LENGTH || !mb_check_encoding($password, 'UTF-8')) {
            throw new InvalidConnectionParametersException('The password is not valid.');
        }
        if (null !== $pinnedAddress && false === filter_var($pinnedAddress, \FILTER_VALIDATE_IP)) {
            throw new InvalidConnectionParametersException('The pinned address is not an IP address.');
        }
    }

    public function password(): string
    {
        return $this->password;
    }

    public function withPassword(#[\SensitiveParameter] string $password): self
    {
        return new self($this->host, $this->port, $this->database, $this->username, $password, $this->pinnedAddress);
    }

    /**
     * L'adresse IP que le driver doit joindre : celle qui a été vérifiée (voir `withAddress`), à défaut l'hôte lui-même.
     */
    public function address(): string
    {
        return $this->pinnedAddress ?? $this->host;
    }

    /**
     * Épingle l'adresse déjà résolue et contrôlée : le driver s'y connecte sans résoudre le nom une seconde fois (une
     * réponse DNS qui changerait entre le contrôle et la connexion n'a plus d'effet). L'hôte reste le nom, pour l'identité
     * (TLS, en-tête Host). L'adresse n'entre jamais dans le JSON chiffré : elle ne vit que le temps de l'ouverture.
     */
    public function withAddress(string $address): self
    {
        return new self($this->host, $this->port, $this->database, $this->username, $this->password, $address);
    }

    public function toJson(): string
    {
        return json_encode([
            'v' => self::FORMAT_VERSION,
            'host' => $this->host,
            'port' => $this->port,
            'database' => $this->database,
            'username' => $this->username,
            'password' => $this->password,
        ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
    }

    public static function fromJson(#[\SensitiveParameter] string $json): self
    {
        try {
            $data = json_decode($json, true, 4, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new InvalidConnectionParametersException('The connection parameters are not valid JSON.');
        }

        if (!\is_array($data) || self::FORMAT_VERSION !== ($data['v'] ?? null)) {
            throw new InvalidConnectionParametersException('The connection parameters have an unsupported format.');
        }

        $host = $data['host'] ?? null;
        $port = $data['port'] ?? null;
        $database = $data['database'] ?? null;
        $username = $data['username'] ?? null;
        $password = $data['password'] ?? null;
        if (!\is_string($host) || !\is_int($port) || !\is_string($database) || !\is_string($username) || !\is_string($password)) {
            throw new InvalidConnectionParametersException('The connection parameters are incomplete.');
        }

        return new self($host, $port, $database, $username, $password);
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return [
            'host' => $this->host,
            'port' => (string) $this->port,
            'database' => $this->database,
            'username' => $this->username,
            'address' => $this->address(),
            'password' => '***',
        ];
    }

    /**
     * @return never
     */
    public function __serialize(): array
    {
        throw new \LogicException('Connection parameters must not be serialized.');
    }

    private static function assertName(string $value, string $label): void
    {
        if ('' === $value || \strlen($value) > self::NAME_MAX_LENGTH || 1 === preg_match('/\p{Cc}/u', $value) || !mb_check_encoding($value, 'UTF-8')) {
            throw new InvalidConnectionParametersException(\sprintf('The %s is not valid.', $label));
        }
    }
}
