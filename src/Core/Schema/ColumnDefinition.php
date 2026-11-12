<?php

declare(strict_types=1);

namespace App\Core\Schema;

use App\Core\Schema\Exception\InvalidSchemaException;

/**
 * Une colonne, avec son type natif tel que le moteur l'écrit (« int unsigned », « character varying(20) »).
 * `invisible` (MySQL 8, MariaDB) : absente de `SELECT *` ; redite telle quelle quand on modifie la colonne.
 * `onUpdate` (MySQL, MariaDB) : l'expression de « ON UPDATE CURRENT_TIMESTAMP(n) », lue dans EXTRA, redite aussi.
 * `compressed` (MariaDB) : le type est rendu SANS le commentaire exécutable COMPRESSED du moteur, la compression est un drapeau.
 */
final readonly class ColumnDefinition
{
    public function __construct(
        public string $name,
        public string $type,
        public bool $nullable,
        public ?string $default,
        public bool $autoIncrement,
        public ?string $comment,
        public bool $invisible = false,
        public ?string $onUpdate = null,
        public bool $compressed = false,
    ) {
        if ('' === $name || '' === $type) {
            throw new InvalidSchemaException('A column needs a name and a type.');
        }
    }
}
