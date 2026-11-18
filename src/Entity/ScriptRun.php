<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Exception\InvalidScriptRunException;
use App\Repository\ScriptRunRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Le suivi de l'exécution d'un script SQL (#169) : où elle en est, instruction par instruction, et si elle est encore en vie.
 * JAMAIS le texte du script ni celui d'une instruction (il peut contenir un secret) : une empreinte du texte et, par instruction,
 * son rang, son état, des nombres et le code SQLSTATE à cinq caractères (jamais le message du moteur, qui peut citer une valeur).
 * La connexion n'est pas une clé étrangère : l'exécution d'une connexion supprimée reste lisible jusqu'à la purge.
 */
#[ORM\Entity(repositoryClass: ScriptRunRepository::class)]
#[ORM\Table(name: 'script_run')]
#[ORM\Index(name: 'idx_script_run_status_heartbeat', columns: ['status', 'heartbeat_at'])]
#[ORM\Index(name: 'idx_script_run_user_created', columns: ['user_id', 'created_at'])]
final class ScriptRun
{
    /** Sans signe de vie depuis ce nombre de secondes, une exécution vivante est déclarée interrompue. */
    public const HEARTBEAT_TIMEOUT_SECONDS = 30;
    public const MAX_STATEMENTS = 500;
    private const STATEMENT_STATUSES = ['applied', 'rolled_back', 'failed', 'not_run'];

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $connectionId;

    #[ORM\Column(length: 64)]
    private string $textHash;

    #[ORM\Column]
    private int $total;

    #[ORM\Column]
    private int $completed = 0;

    #[ORM\Column]
    private bool $dryRun;

    #[ORM\Column(length: 16, enumType: ScriptRunStatus::class)]
    private ScriptRunStatus $status = ScriptRunStatus::Queued;

    #[ORM\Column]
    private bool $cancelRequested = false;

    /** @var list<array{rank: int, status: string, affectedRows: int|null, durationMs: int, sqlstate: string|null}> */
    #[ORM\Column(type: Types::JSON)]
    private array $statements = [];

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $heartbeatAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    public function __construct(Uuid $id, User $user, Uuid $connectionId, string $textHash, int $total, bool $dryRun, \DateTimeImmutable $now)
    {
        if (1 !== preg_match('/^[0-9a-f]{64}$/D', $textHash)) {
            throw new InvalidScriptRunException('The text of a script is kept as a SHA-256 fingerprint only.');
        }
        if ($total < 1 || $total > self::MAX_STATEMENTS) {
            throw new InvalidScriptRunException(\sprintf('A script has between 1 and %d statements.', self::MAX_STATEMENTS));
        }
        $this->id = $id;
        $this->user = $user;
        $this->connectionId = $connectionId;
        $this->textHash = $textHash;
        $this->total = $total;
        $this->dryRun = $dryRun;
        $this->createdAt = $now;
        $this->heartbeatAt = $now;
    }

    public function start(\DateTimeImmutable $now): void
    {
        if (ScriptRunStatus::Queued !== $this->status) {
            throw new InvalidScriptRunException('Only a queued run can start.');
        }
        $this->status = ScriptRunStatus::Running;
        $this->startedAt = $now;
        $this->heartbeatAt = $now;
    }

    public function beat(\DateTimeImmutable $now): void
    {
        $this->requireRunning();
        $this->heartbeatAt = $now;
    }

    /**
     * Note l'issue de l'instruction `$rank` (de 1 à total, dans l'ordre). Ni texte ni message du moteur : le code SQLSTATE seulement.
     */
    public function recordStatement(int $rank, string $status, ?int $affectedRows, int $durationMs, ?string $sqlstate, \DateTimeImmutable $now): void
    {
        $this->requireRunning();
        if ($rank !== $this->completed + 1 || $rank > $this->total) {
            throw new InvalidScriptRunException(\sprintf('Statements are recorded in order: expected rank %d of %d.', $this->completed + 1, $this->total));
        }
        if (!\in_array($status, self::STATEMENT_STATUSES, true)) {
            throw new InvalidScriptRunException('Unknown statement status.');
        }
        if (null !== $sqlstate && 1 !== preg_match('/^[0-9A-Z]{5}$/D', $sqlstate)) {
            throw new InvalidScriptRunException('Only the five-character SQLSTATE of an error is kept, never the message of the engine.');
        }
        if ($durationMs < 0 || (null !== $affectedRows && $affectedRows < 0)) {
            throw new InvalidScriptRunException('A measure cannot be negative.');
        }
        $this->statements[] = ['rank' => $rank, 'status' => $status, 'affectedRows' => $affectedRows, 'durationMs' => $durationMs, 'sqlstate' => $sqlstate];
        $this->completed = $rank;
        $this->heartbeatAt = $now;
    }

    public function requestCancel(): void
    {
        if ($this->status->isTerminal()) {
            throw new InvalidScriptRunException('A finished run cannot be cancelled.');
        }
        $this->cancelRequested = true;
    }

    public function finish(ScriptRunStatus $status, \DateTimeImmutable $now): void
    {
        if ($this->status->isTerminal()) {
            throw new InvalidScriptRunException('A finished run cannot finish again.');
        }
        if (!$status->canBeReportedAtTheEnd()) {
            throw new InvalidScriptRunException('The status "'.$status->value.'" is not a way to finish a run.');
        }
        $this->status = $status;
        $this->finishedAt = $now;
    }

    /**
     * Déclare interrompue une exécution vivante dont plus rien ne donne signe de vie ; ce qui a été appliqué reste dit.
     */
    public function interrupt(\DateTimeImmutable $now): void
    {
        if ($this->status->isTerminal()) {
            throw new InvalidScriptRunException('A finished run cannot be interrupted.');
        }
        $this->status = ScriptRunStatus::Interrupted;
        $this->finishedAt = $now;
    }

    public function isStale(\DateTimeImmutable $now): bool
    {
        return !$this->status->isTerminal() && $this->heartbeatAt->modify(\sprintf('+%d seconds', self::HEARTBEAT_TIMEOUT_SECONDS)) < $now;
    }

    private function requireRunning(): void
    {
        if (ScriptRunStatus::Running !== $this->status) {
            throw new InvalidScriptRunException('The run is not running.');
        }
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getConnectionId(): Uuid
    {
        return $this->connectionId;
    }

    public function getTextHash(): string
    {
        return $this->textHash;
    }

    public function getTotal(): int
    {
        return $this->total;
    }

    public function getCompleted(): int
    {
        return $this->completed;
    }

    public function isDryRun(): bool
    {
        return $this->dryRun;
    }

    public function getStatus(): ScriptRunStatus
    {
        return $this->status;
    }

    public function isCancelRequested(): bool
    {
        return $this->cancelRequested;
    }

    /**
     * @return list<array{rank: int, status: string, affectedRows: int|null, durationMs: int, sqlstate: string|null}>
     */
    public function getStatements(): array
    {
        return $this->statements;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getHeartbeatAt(): \DateTimeImmutable
    {
        return $this->heartbeatAt;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }
}
