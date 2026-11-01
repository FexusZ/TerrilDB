<?php

declare(strict_types=1);

namespace App\Security;

use App\Crypto\DerivedKey;
use App\Crypto\Exception\CryptoException;
use App\Crypto\RecoveryPhrase;
use App\Crypto\SessionKeyWrapper;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\Exception\InvalidRecoveryException;
use Psr\Clock\ClockInterface;

/**
 * Phrase de récupération (facultative). Elle enveloppe une copie de la clé de chiffrement de l'utilisateur ; le serveur
 * ne garde que cette enveloppe, jamais la phrase : sans elle, rien n'est lisible, comme pour un jeton d'API.
 *
 * Elle sert à un seul geste : choisir un nouveau mot de passe quand l'ancien est perdu. La clé retrouvée rechiffre
 * le secret TOTP et les connexions (PasswordChanger), le second facteur reste donc exigé à la connexion suivante.
 * Comme tout changement de mot de passe, la réinitialisation change la clé : la phrase utilisée, devenue inutile, est
 * supprimée (il faut en générer une nouvelle) ; un changement de mot de passe ordinaire fait de même.
 */
final readonly class RecoveryPhraseService
{
    public function __construct(
        private SessionKeyWrapper $wrapper,
        private PasswordChanger $changer,
        private UserRepository $users,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Génère une phrase (la précédente, s'il y en avait une, ne sert plus). Elle n'est rendue qu'ici : à montrer une fois.
     */
    public function enable(User $user, DerivedKey $key): RecoveryPhrase
    {
        $phrase = RecoveryPhrase::generate();
        $user->enableRecovery(
            $this->wrapper->wrap($key, $phrase->unlockSecret(), self::context($user)),
            \DateTimeImmutable::createFromInterface($this->clock->now()),
        );
        $this->users->save($user);

        return $phrase;
    }

    public function disable(User $user): void
    {
        $user->disableRecovery();
        $this->users->save($user);
    }

    /**
     * @return DerivedKey la nouvelle clé, pour ouvrir la session
     *
     * @throws InvalidRecoveryException phrase absente, mal formée, fausse, ou compte sans phrase : toujours le même message
     */
    public function reset(User $user, #[\SensitiveParameter] string $typedPhrase, #[\SensitiveParameter] string $newPassword): DerivedKey
    {
        $wrapped = $user->getRecoveryKey();

        try {
            $phrase = RecoveryPhrase::parse($typedPhrase);
            if (null === $wrapped) {
                throw InvalidRecoveryException::create();
            }
            $oldKey = $this->wrapper->unwrap($wrapped, $phrase->unlockSecret(), self::context($user));
        } catch (CryptoException $failure) {
            throw InvalidRecoveryException::create($failure);
        }

        try {
            return $this->changer->resetWithKey($user, $oldKey, $newPassword);
        } finally {
            $oldKey->wipe();
        }
    }

    private static function context(User $user): string
    {
        return 'recovery:'.$user->getId()->toRfc4122();
    }
}
