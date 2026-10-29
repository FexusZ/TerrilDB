<?php

declare(strict_types=1);

namespace App\Crypto;

use App\Crypto\Exception\EmptyPasswordException;

/**
 * Rechiffre les secrets d'un utilisateur lors d'un changement de mot de passe.
 *
 * Tout-ou-rien : chaque secret est d'abord déchiffré avec l'ancienne clé, et rien n'est rechiffré
 * tant qu'un seul échoue. L'appelant persiste ensuite le résultat dans une seule transaction.
 *
 * Sans aucun secret, rien ne permet de vérifier l'ancien mot de passe ici : cette vérification
 * appartient à l'authentification (hash du mot de passe), pas au chiffrement.
 */
final readonly class SecretRotator
{
    public function __construct(
        private KeyDerivation $derivation,
        private SecretCipher $cipher,
    ) {
    }

    /**
     * @param array<array-key, string> $secrets chiffrés indexés par identifiant (utilisé comme contexte authentifié)
     */
    public function rotate(
        #[\SensitiveParameter] string $oldPassword,
        KdfParameters $oldParameters,
        #[\SensitiveParameter] string $newPassword,
        array $secrets,
    ): RotationResult {
        if ('' === $newPassword) {
            throw new EmptyPasswordException('The new password must not be empty.');
        }

        $plaintexts = $this->decryptAll($oldPassword, $oldParameters, $secrets);

        $newParameters = $this->derivation->newParameters();
        $newKey = $this->derivation->derive($newPassword, $newParameters);

        try {
            $rotated = [];
            foreach ($plaintexts as $id => $plaintext) {
                $rotated[$id] = $this->cipher->encrypt($plaintext, $newKey, (string) $id);
            }
        } catch (\Throwable $e) {
            $newKey->wipe();

            throw $e;
        } finally {
            $this->wipeAll($plaintexts);
        }

        return new RotationResult($newParameters, $newKey, $rotated);
    }

    /**
     * @param array<array-key, string> $secrets
     *
     * @return array<array-key, string>
     */
    private function decryptAll(string $oldPassword, KdfParameters $oldParameters, array $secrets): array
    {
        $oldKey = $this->derivation->derive($oldPassword, $oldParameters);

        $plaintexts = [];
        try {
            foreach ($secrets as $id => $blob) {
                $plaintexts[$id] = $this->cipher->decrypt($blob, $oldKey, (string) $id);
            }

            return $plaintexts;
        } catch (\Throwable $e) {
            $this->wipeAll($plaintexts);

            throw $e;
        } finally {
            $oldKey->wipe();
        }
    }

    /**
     * @param array<array-key, string> $plaintexts
     */
    private function wipeAll(array &$plaintexts): void
    {
        // Par référence : sodium_memzero n'efface sur place que si la chaîne n'est pas partagée.
        foreach ($plaintexts as &$plaintext) {
            sodium_memzero($plaintext);
        }
        unset($plaintext);
    }
}
