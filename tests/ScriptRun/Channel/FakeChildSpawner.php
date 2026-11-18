<?php

declare(strict_types=1);

namespace App\Tests\ScriptRun\Channel;

use App\ScriptRun\Channel\ChildSpawner;
use App\ScriptRun\Channel\ScriptChild;

/**
 * Un lanceur de test : note les messages reçus et rend des « processus » dont le test décide s'ils tournent encore.
 */
final class FakeChildSpawner implements ChildSpawner
{
    /** @var list<string> */
    public array $messages = [];
    /** @var list<FakeChild> */
    public array $children = [];

    public function spawn(string $json): ScriptChild
    {
        $this->messages[] = $json;

        return $this->children[] = new FakeChild();
    }
}
