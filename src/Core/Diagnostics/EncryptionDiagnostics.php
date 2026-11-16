<?php

declare(strict_types=1);

namespace App\Core\Diagnostics;

/**
 * Observation de la connexion ouverte par TerrilDB : est-elle chiffrée ? Rien ne change aux paramètres de connexion.
 */
interface EncryptionDiagnostics
{
    public function status(): DiagnosticStatus;

    public function encryption(): ConnectionEncryption;
}
