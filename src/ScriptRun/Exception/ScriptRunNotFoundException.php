<?php

declare(strict_types=1);

namespace App\ScriptRun\Exception;

/**
 * Exécution inconnue OU d'un autre compte : les deux sont indiscernables.
 */
final class ScriptRunNotFoundException extends \RuntimeException
{
}
