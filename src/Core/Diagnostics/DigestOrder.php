<?php

declare(strict_types=1);

namespace App\Core\Diagnostics;

/**
 * Le classement des empreintes. Le temps TOTAL (fréquence x durée) est le bon défaut : la requête la plus lente une fois
 * coûte souvent moins que celle de quelques millisecondes lancée des millions de fois.
 */
enum DigestOrder: string
{
    case TotalTime = 'total_time';
    case MeanTime = 'mean_time';
    case Calls = 'calls';
}
