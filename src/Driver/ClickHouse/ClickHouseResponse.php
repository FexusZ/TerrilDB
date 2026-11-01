<?php

declare(strict_types=1);

namespace App\Driver\ClickHouse;

/**
 * Réponse de ClickHouse au format JSONCompact : colonnes, lignes (valeurs JSON brutes) et, pour une instruction
 * sans résultat, le nombre de lignes écrites annoncé par le moteur.
 */
final readonly class ClickHouseResponse
{
    /**
     * @param list<string>      $columns
     * @param list<string>      $types   type ClickHouse de chaque colonne
     * @param list<list<mixed>> $rows
     */
    public function __construct(
        public array $columns,
        public array $types,
        public array $rows,
        public int $writtenRows,
    ) {
    }

    /**
     * Les lignes sous forme « colonne => valeur », pour les requêtes de catalogue.
     *
     * @return list<array<string, mixed>>
     */
    public function records(): array
    {
        $records = [];
        foreach ($this->rows as $row) {
            $records[] = array_combine($this->columns, $row);
        }

        return $records;
    }
}
