<?php

declare(strict_types=1);

namespace App\Http\Mcp;

/**
 * Les formes compactes qu'un assistant peut demander pour le résultat d'une requête.
 */
enum ResultFormat: string
{
    case Csv = 'csv';
    case Json = 'json';
}
