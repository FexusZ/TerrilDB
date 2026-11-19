<?php

declare(strict_types=1);

namespace App\ScriptRun;

use App\Audit\AuditActor;
use App\Audit\AuditContext;
use App\Core\Connection\ConnectionParameters;
use App\Core\Connection\Engine;
use App\Core\Query\QueryOptions;
use App\Core\Query\WritePermissions;
use App\ScriptRun\Exception\InvalidScriptRunRequestException;
use Symfony\Component\Uid\Uuid;

/**
 * Ce que la requête de lancement envoie au worker (#169) : l'exécution à jouer, les identifiants DÉCHIFFRÉS de la cible
 * (adresse déjà contrôlée et épinglée), le script et ses options. Il ne vit qu'en mémoire : jamais sur disque, jamais dans la base
 * interne ni dans l'audit, jamais sérialisé par PHP. Une connexion avec rebond n'est pas portée pour l'instant.
 */
final readonly class ScriptRunRequest
{
    private const FORMAT_VERSION = 1;
    public const MAX_SCRIPT_BYTES = 1_000_000;
    /** Le script, plus les identifiants et les options : au-delà, le message est refusé sans être lu. */
    public const MAX_JSON_BYTES = 2_000_000;

    public function __construct(
        public Uuid $runId,
        public Engine $engine,
        #[\SensitiveParameter] public ConnectionParameters $parameters,
        public string $script,
        public WritePermissions $writes,
        public bool $confirmed,
        public bool $dryRun,
        public int $timeoutMs,
        /** Qui a lancé l'exécution : les lignes d'audit des instructions s'écrivent à son nom, depuis le processus du worker. */
        public AuditContext $audit,
        /** La console d'une personne connectée (voir ScriptOptions::$lenient) : le worker juge le script de la même façon que la route. */
        public bool $lenient = false,
    ) {
        if (null !== $parameters->transport) {
            throw new InvalidScriptRunRequestException('A connection through a jump cannot run a detached script yet.');
        }
        if ('' === trim($script) || \strlen($script) > self::MAX_SCRIPT_BYTES) {
            throw new InvalidScriptRunRequestException('The script is empty or too large.');
        }
        if ($timeoutMs < QueryOptions::MIN_TIMEOUT_MS || $timeoutMs > QueryOptions::MAX_TIMEOUT_MS) {
            throw new InvalidScriptRunRequestException('The timeout is out of bounds.');
        }
    }

    public function toJson(): string
    {
        $address = $this->parameters->address();

        return json_encode([
            'v' => self::FORMAT_VERSION,
            'run' => $this->runId->toRfc4122(),
            'engine' => $this->engine->value,
            'connection' => $this->parameters->toJson(),
            'address' => $address === $this->parameters->host ? null : $address,
            'schema' => $this->parameters->schema,
            'script' => $this->script,
            'writeData' => $this->writes->data,
            'writeSchema' => $this->writes->schema,
            'confirmed' => $this->confirmed,
            'dryRun' => $this->dryRun,
            'lenient' => $this->lenient,
            'timeoutMs' => $this->timeoutMs,
            'audit' => [
                'actor' => $this->audit->actor->value,
                'user' => $this->audit->userId?->toRfc4122(),
                'token' => $this->audit->tokenId?->toRfc4122(),
                'ip' => $this->audit->ip,
            ],
        ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
    }

    /**
     * @throws InvalidScriptRunRequestException sans jamais citer le message reçu
     */
    public static function fromJson(#[\SensitiveParameter] string $json): self
    {
        if (\strlen($json) > self::MAX_JSON_BYTES) {
            throw new InvalidScriptRunRequestException('The message is too large.');
        }
        try {
            $data = json_decode($json, true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new InvalidScriptRunRequestException('The message is not valid JSON.');
        }
        if (!\is_array($data) || self::FORMAT_VERSION !== ($data['v'] ?? null)) {
            throw new InvalidScriptRunRequestException('The message has an unsupported format.');
        }

        $run = $data['run'] ?? null;
        $engine = \is_string($data['engine'] ?? null) ? Engine::tryFrom($data['engine']) : null;
        $connection = $data['connection'] ?? null;
        $script = $data['script'] ?? null;
        $timeoutMs = $data['timeoutMs'] ?? null;
        if (!\is_string($run) || !Uuid::isValid($run) || null === $engine || !\is_string($connection) || !\is_string($script) || !\is_int($timeoutMs)
            || !\is_bool($data['writeData'] ?? null) || !\is_bool($data['writeSchema'] ?? null) || !\is_bool($data['confirmed'] ?? null) || !\is_bool($data['dryRun'] ?? null)) {
            throw new InvalidScriptRunRequestException('The message is incomplete.');
        }

        $audit = self::auditFrom($data['audit'] ?? null);

        try {
            $parameters = ConnectionParameters::fromJson($connection);
            if (\is_string($data['address'] ?? null)) {
                $parameters = $parameters->withAddress($data['address']);
            }
            if (\is_string($data['schema'] ?? null)) {
                $parameters = $parameters->withSchema($data['schema']);
            }
        } catch (\DomainException) {
            throw new InvalidScriptRunRequestException('The connection of the message is not valid.');
        }

        return new self(Uuid::fromString($run), $engine, $parameters, $script, new WritePermissions($data['writeData'], $data['writeSchema']), $data['confirmed'], $data['dryRun'], $timeoutMs, $audit, true === ($data['lenient'] ?? null));
    }

    private static function auditFrom(mixed $data): AuditContext
    {
        if (!\is_array($data)) {
            throw new InvalidScriptRunRequestException('The message names nobody.');
        }
        $actor = \is_string($data['actor'] ?? null) ? AuditActor::tryFrom($data['actor']) : null;
        $user = $data['user'] ?? null;
        $token = $data['token'] ?? null;
        $ip = $data['ip'] ?? null;
        if (null === $actor || (null !== $user && (!\is_string($user) || !Uuid::isValid($user))) || (null !== $token && (!\is_string($token) || !Uuid::isValid($token))) || (null !== $ip && (!\is_string($ip) || \strlen($ip) > 45))) {
            throw new InvalidScriptRunRequestException('The author of the message is not valid.');
        }

        return new AuditContext($actor, \is_string($user) ? Uuid::fromString($user) : null, \is_string($token) ? Uuid::fromString($token) : null, $ip);
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['run' => $this->runId->toRfc4122(), 'engine' => $this->engine->value, 'script' => '('.\strlen($this->script).' octets)', 'connection' => '***'];
    }

    public function __serialize(): never
    {
        throw new \LogicException('A script run request carries credentials and is never serialized.');
    }
}
