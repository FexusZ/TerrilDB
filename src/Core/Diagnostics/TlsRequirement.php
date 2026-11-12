<?php

declare(strict_types=1);

namespace App\Core\Diagnostics;

/** Ce qu'un compte exige de la connexion qui l'ouvre (ssl_type). */
enum TlsRequirement: string
{
    case None = 'none';
    case Any = 'any';
    case X509 = 'x509';
    case Specified = 'specified';
}
