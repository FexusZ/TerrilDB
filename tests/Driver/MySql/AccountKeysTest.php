<?php

declare(strict_types=1);

namespace App\Tests\Driver\MySql;

use App\Driver\MySql\AccountKeys;
use PHPUnit\Framework\TestCase;

/**
 * information_schema écrit un compte `'utilisateur'@'hôte'` sans échapper les guillemets : deux comptes différents pourraient
 * donner le même texte. Un texte partagé n'attribue de droits à personne.
 */
final class AccountKeysTest extends TestCase
{
    public function testAKeyHeldByTwoAccountsIsAmbiguous(): void
    {
        self::assertSame(["'a'@'b'@'h'" => true], AccountKeys::ambiguous(["'a'@'b'@'h'", "'c'@'h'", "'a'@'b'@'h'"]));
    }

    public function testDistinctKeysAreNeverAmbiguous(): void
    {
        self::assertSame([], AccountKeys::ambiguous(["'a'@'h'", "'a'@'i'"]));
    }

    public function testTheKeyQuotesTheUserAndTheHost(): void
    {
        self::assertSame("'a'@'b'@'h'", AccountKeys::of("a'@'b", 'h'));
    }
}
