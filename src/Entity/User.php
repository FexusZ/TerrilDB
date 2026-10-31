<?php

declare(strict_types=1);

namespace App\Entity;

use App\Crypto\KdfParameters;
use App\Entity\Exception\InvalidUserException;
use App\Repository\UserRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Compte utilisateur. On s'y connecte par e-mail ou par nom d'utilisateur, tous deux uniques et en
 * minuscules ; le nom d'utilisateur ne peut pas contenir « @ », ce qui rend la distinction sans ambiguïté.
 *
 * Aucun secret en clair : le mot de passe n'existe que sous forme de hash d'authentification, et le secret
 * TOTP que chiffré par la clé dérivée du mot de passe (il suit donc la rotation au changement de mot de passe).
 */
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'app_user')]
final class User
{
    private const ARGON2ID_PREFIX = '$argon2id$';
    private const EMAIL_PATTERN = '/^[^@\s]+@[^@\s]+\.[^@\s]+$/D';
    private const EMAIL_MAX_LENGTH = 254;
    private const USERNAME_PATTERN = '/^[a-z0-9][a-z0-9._-]{2,31}$/D';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(length: self::EMAIL_MAX_LENGTH, unique: true)]
    private string $email;

    #[ORM\Column(length: 32, unique: true)]
    private string $username;

    #[ORM\Column(type: Types::TEXT)]
    private string $passwordHash;

    #[ORM\Column(length: 255)]
    private string $kdfParameters;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $totpSecret = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $totpLastStep = 0;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $recoveryCodeHashes = [];

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        string $email,
        string $username,
        string $passwordHash,
        KdfParameters $kdfParameters,
        \DateTimeImmutable $createdAt,
    ) {
        $this->id = Uuid::v7();
        $this->email = self::normalizeEmail($email);
        $this->username = self::normalizeUsername($username);
        $this->passwordHash = self::assertPasswordHash($passwordHash);
        $this->kdfParameters = $kdfParameters->toString();
        // Colonne sans fuseau : toujours en UTC, quel que soit le fuseau du serveur.
        $this->createdAt = $createdAt->setTimezone(new \DateTimeZone('UTC'));
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function getPasswordHash(): string
    {
        return $this->passwordHash;
    }

    public function getKdfParameters(): KdfParameters
    {
        return KdfParameters::fromString($this->kdfParameters);
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Changement de mot de passe : hash, paramètres de dérivation et secret TOTP rechiffré vont ensemble,
     * pour qu'aucun état intermédiaire (secret chiffré avec l'ancienne clé) ne soit jamais enregistré.
     */
    public function changePassword(string $passwordHash, KdfParameters $kdfParameters, ?string $rotatedTotpSecret): void
    {
        $passwordHash = self::assertPasswordHash($passwordHash);
        if ($this->isTotpEnabled() && (null === $rotatedTotpSecret || '' === $rotatedTotpSecret)) {
            throw new InvalidUserException('The TOTP secret must be re-encrypted along with the password.');
        }
        if (!$this->isTotpEnabled() && null !== $rotatedTotpSecret) {
            throw new InvalidUserException('There is no TOTP secret to re-encrypt.');
        }

        $this->passwordHash = $passwordHash;
        $this->kdfParameters = $kdfParameters->toString();
        if (null !== $rotatedTotpSecret) {
            $this->totpSecret = $rotatedTotpSecret;
        }
    }

    public function isTotpEnabled(): bool
    {
        return null !== $this->totpSecret;
    }

    /**
     * Le secret TOTP chiffré (jamais en clair).
     */
    public function getTotpSecret(): ?string
    {
        return $this->totpSecret;
    }

    public function getTotpLastStep(): int
    {
        return $this->totpLastStep;
    }

    /**
     * @return list<string>
     */
    public function getRecoveryCodeHashes(): array
    {
        return $this->recoveryCodeHashes;
    }

    /**
     * @param list<string> $recoveryCodeHashes
     */
    public function enableTotp(string $encryptedSecret, array $recoveryCodeHashes): void
    {
        if ('' === $encryptedSecret) {
            throw new InvalidUserException('The encrypted TOTP secret must not be empty.');
        }

        $this->totpSecret = $encryptedSecret;
        $this->totpLastStep = 0;
        $this->recoveryCodeHashes = $recoveryCodeHashes;
    }

    public function disableTotp(): void
    {
        $this->totpSecret = null;
        $this->totpLastStep = 0;
        $this->recoveryCodeHashes = [];
    }

    /**
     * Mémorise la période TOTP acceptée : un code ne peut pas être rejoué.
     */
    public function recordTotpStep(int $step): void
    {
        $this->assertTotpEnabled();
        if ($step <= $this->totpLastStep) {
            throw new InvalidUserException('A TOTP step can only move forward.');
        }

        $this->totpLastStep = $step;
    }

    /**
     * @param list<string> $recoveryCodeHashes
     */
    public function replaceRecoveryCodeHashes(array $recoveryCodeHashes): void
    {
        $this->assertTotpEnabled();

        $this->recoveryCodeHashes = $recoveryCodeHashes;
    }

    private function assertTotpEnabled(): void
    {
        if (!$this->isTotpEnabled()) {
            throw new InvalidUserException('TOTP is not enabled for this user.');
        }
    }

    private static function normalizeEmail(string $email): string
    {
        $email = mb_strtolower(trim($email));
        if (\strlen($email) > self::EMAIL_MAX_LENGTH || 1 !== preg_match(self::EMAIL_PATTERN, $email)) {
            throw new InvalidUserException('The email address is not valid.');
        }

        return $email;
    }

    private static function normalizeUsername(string $username): string
    {
        $username = strtolower($username);
        if (1 !== preg_match(self::USERNAME_PATTERN, $username)) {
            throw new InvalidUserException('The username must be 3 to 32 characters: lowercase letters, digits, dot, dash or underscore, starting with a letter or digit.');
        }

        return $username;
    }

    private static function assertPasswordHash(string $passwordHash): string
    {
        if (!str_starts_with($passwordHash, self::ARGON2ID_PREFIX)) {
            throw new InvalidUserException('The password hash must be an Argon2id hash.');
        }

        return $passwordHash;
    }
}
