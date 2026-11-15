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
    private const FORMAT_VERSION_TRANSPORT = 2;
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
        public ?string $schema = null,
        public ?Transport $transport = null,
    ) {
        if ('' === $host || \strlen($host) > self::HOST_MAX_LENGTH || 1 !== preg_match('/^[^\s\p{Cc}]+$/uD', $host)) {
            throw new InvalidConnectionParametersException('The host is not valid.');
        }
        if ($port < 1 || $port > 65535) {
            throw new InvalidConnectionParametersException('The port must be between 1 and 65535.');
        }
        // La base est facultative : sans elle on joint le serveur, puis on choisit parmi ses bases.
        if ('' !== $database) {
            self::assertName($database, 'database');
        }
        if (null !== $schema) {
            self::assertName($schema, 'schema');
        }
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
        return new self($this->host, $this->port, $this->database, $this->username, $password, $this->pinnedAddress, $this->schema, $this->transport);
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
        return new self($this->host, $this->port, $this->database, $this->username, $this->password, $address, $this->schema, $this->transport);
    }

    /**
     * Les mêmes paramètres sur une autre base du même serveur (hôte, port, identifiants et adresse épinglée inchangés).
     */
    public function withDatabase(string $database): self
    {
        return new self($this->host, $this->port, $database, $this->username, $this->password, $this->pinnedAddress, $this->schema, $this->transport);
    }

    /**
     * Le schéma à explorer (PostgreSQL : un niveau sous la base). Comme l'adresse épinglée, il ne vit que le temps de
     * l'ouverture et n'entre jamais dans le JSON chiffré.
     */
    public function withSchema(?string $schema): self
    {
        return new self($this->host, $this->port, $this->database, $this->username, $this->password, $this->pinnedAddress, '' === $schema ? null : $schema, $this->transport);
    }

    /**
     * Le premier saut que le serveur joint vraiment : le bastion s'il y en a un, sinon la base elle-même.
     *
     * @return array{string, int}
     */
    public function firstHop(): array
    {
        return null === $this->transport ? [$this->host, $this->port] : [$this->transport->host, $this->transport->port];
    }

    public function toJson(): string
    {
        $data = [
            'v' => null === $this->transport ? self::FORMAT_VERSION : self::FORMAT_VERSION_TRANSPORT,
            'host' => $this->host,
            'port' => $this->port,
            'database' => $this->database,
            'username' => $this->username,
            'password' => $this->password,
        ];
        if (null !== $this->transport) {
            $data['transport'] = $this->transport->toArray();
        }

        return json_encode($data, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
    }

    public static function fromJson(#[\SensitiveParameter] string $json): self
    {
        try {
            $data = json_decode($json, true, 4, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new InvalidConnectionParametersException('The connection parameters are not valid JSON.');
        }

        if (!\is_array($data) || !\in_array($data['v'] ?? null, [self::FORMAT_VERSION, self::FORMAT_VERSION_TRANSPORT], true)) {
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

        // Le format 2 existe pour porter un transport : sans lui, il est illisible.
        $transport = self::FORMAT_VERSION_TRANSPORT === $data['v'] ? self::transportFrom($data['transport'] ?? null) : null;

        return new self($host, $port, $database, $username, $password, transport: $transport);
    }

    private static function transportFrom(mixed $data): Transport
    {
        $text = static fn (string $key): ?string => \is_array($data) && \is_string($data[$key] ?? null) ? $data[$key] : null;
        $number = static fn (string $key): ?int => \is_array($data) && \is_int($data[$key] ?? null) ? $data[$key] : null;

        $transport = match ($text('type')) {
            'ssh' => null !== $text('host') && null !== $number('port') && null !== $text('username') && null !== $text('privateKey')
                ? new SshJump($text('host'), $number('port'), $text('username'), $text('privateKey'), $text('hostKeyFingerprint'))
                : null,
            default => null,
        };

        return $transport ?? throw new InvalidConnectionParametersException('The connection transport is not valid.');
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
            'transport' => null === $this->transport ? '' : \sprintf('ssh %s:%d', $this->transport->host, $this->transport->port),
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
