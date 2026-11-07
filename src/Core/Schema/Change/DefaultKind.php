<?php

declare(strict_types=1);

namespace App\Core\Schema\Change;

enum DefaultKind: string
{
    case Null = 'null';
    case Number = 'number';
    case Literal = 'literal';
    case Expression = 'expression';
}
