<?php

declare(strict_types=1);

namespace App\Core\Query\Script;

/**
 * Exécute un script DÉJÀ jugé par le garde (voir ScriptService), instruction par instruction, dans la transaction du moteur
 * quand il en a une. S'arrête à la première erreur : une erreur d'instruction n'est pas une exception, elle est dans le
 * compte rendu. `timeoutMs` borne le script ENTIER ; `dryRun` demande d'annuler à la fin même si tout réussit.
 */
interface ScriptExecutor
{
    /**
     * @param list<ScriptStatement> $statements
     */
    public function execute(array $statements, int $timeoutMs, bool $dryRun): ScriptResult;
}
