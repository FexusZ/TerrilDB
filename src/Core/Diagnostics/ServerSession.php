<?php

declare(strict_types=1);

namespace App\Core\Diagnostics;

use App\Core\Guard\SqlDialect;

/**
 * Une session (ou un processus) ouverte sur le serveur cible. Le texte de la requête en cours peut citer des valeurs
 * d'un autre utilisateur : ses littéraux sont masqués puis il est raccourci ici, à la construction, quel que soit le moteur
 * (`QueryText`). Un texte qu'on ne sait pas lire sans risque n'est pas rendu : `queryRedacted`.
 */
final readonly class ServerSession
{
    public const MAX_QUERY_LENGTH = QueryText::MAX_LENGTH;

    public ?string $query;
    public bool $queryRedacted;

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
        SqlDialect $dialect,
    ) {
        if ('' === $id) {
            throw new \InvalidArgumentException('A session needs an identifier.');
        }
        $text = QueryText::of($query, $dialect);
        $this->query = $text->text;
        $this->queryRedacted = $text->redacted;
    }
}
