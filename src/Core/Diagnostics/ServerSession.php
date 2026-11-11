<?php

declare(strict_types=1);

namespace App\Core\Diagnostics;

/**
 * Une session (ou un processus) ouverte sur le serveur cible. Le texte de la requête en cours peut citer des valeurs
 * d'un autre utilisateur : il est donc toujours raccourci ici, à la construction, quel que soit le moteur.
 */
final readonly class ServerSession
{
    public const MAX_QUERY_LENGTH = QueryText::MAX_LENGTH;

    public ?string $query;

    public function __construct(
        public string $id,
        public ?string $user,
        /** Adresse du client, sans le port. */
        public ?string $client,
        public ?string $database,
        public ?string $state,
        public ?int $seconds,
        ?string $query,
        /** Vrai pour la session qui pose la question (celle de TerrilDB). */
        public bool $current,
    ) {
        if ('' === $id) {
            throw new \InvalidArgumentException('A session needs an identifier.');
        }
        $this->query = QueryText::shorten($query);
    }
}
