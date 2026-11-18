<?php

declare(strict_types=1);

namespace App\ScriptRun\Channel;

interface ChildSpawner
{
    /**
     * Lance un processus qui joue le message `$json` (identifiants compris : par son entrée standard seulement, jamais en argument,
     * jamais dans un fichier).
     */
    public function spawn(string $json): ScriptChild;
}
