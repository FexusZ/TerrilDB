<?php

declare(strict_types=1);

namespace App\Core\Schema\Change\Algorithm;

/**
 * Ce que le moteur fera probablement d'UNE instruction, pour l'aperçu. Jamais un essai : rien n'a été exécuté, c'est une règle
 * vérifiée à l'avance contre les vrais moteurs. `blocksWrites` : l'instruction bloque les écritures tant qu'elle travaille (nul
 * si non vérifié) ; `copiesTable` : elle recopie toute la table (nul si non vérifié) ; `estimatedExtraBytes` : place disque
 * supplémentaire probable (taille des données et des index, seulement pour une copie ou une réécriture) ; `reason` : code stable
 * de ce qui empêche de conclure (`unknown`), nul sinon.
 */
final readonly class AlgorithmAdvice
{
    public function __construct(
        public AlterAlgorithm $algorithm,
        public ?bool $blocksWrites,
        public ?bool $copiesTable,
        public ?int $estimatedExtraBytes,
        public ?string $reason = null,
    ) {
    }

    public static function unknown(string $reason): self
    {
        return new self(AlterAlgorithm::Unknown, null, null, null, $reason);
    }
}
