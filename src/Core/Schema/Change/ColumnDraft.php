<?php

declare(strict_types=1);

namespace App\Core\Schema\Change;

/**
 * Une colonne telle qu'on la veut. `originalName` dit de quelle colonne actuelle elle vient (renommage compris) ; nul, c'est
 * une colonne nouvelle. Le type et la valeur par défaut sont vérifiés à l'usage (`ColumnType`, `DefaultValue`).
 */
final readonly class ColumnDraft
{
    public function __construct(
        public ?string $originalName,
        public string $name,
        public string $type,
        public bool $nullable,
        public ?string $default,
        public bool $autoIncrement,
    ) {
        NameRule::check($name, 'column');
        ColumnType::validated($type);
        DefaultValue::parse($default ?? '');
    }

    public function validatedType(): string
    {
        return ColumnType::validated($this->type);
    }

    public function defaultValue(): ?DefaultValue
    {
        return DefaultValue::parse($this->default ?? '');
    }
}
