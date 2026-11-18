<?php

declare(strict_types=1);

namespace App\ScriptRun\Channel;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Lance `bin/console app:script:run-child` : le message (identifiants compris) entre par l'entrée standard du processus, jamais par un
 * argument (visible dans la liste des processus) ni par un fichier ni par l'environnement. La sortie d'erreur reste celle du worker
 * (les journaux du conteneur) ; la sortie standard est jetée.
 */
final readonly class ProcessChildSpawner implements ChildSpawner
{
    /** @var list<string> */
    private array $command;

    /**
     * @param list<string>|null $command surcharge pour les tests ; par défaut `php bin/console app:script:run-child`
     */
    public function __construct(#[Autowire('%kernel.project_dir%')] string $projectDir, ?array $command = null)
    {
        $this->command = $command ?? [\PHP_BINARY, $projectDir.'/bin/console', 'app:script:run-child'];
    }

    public function spawn(string $json): ScriptChild
    {
        $process = proc_open($this->command, [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => \STDERR], $pipes);
        if (!\is_resource($process)) {
            throw new \RuntimeException('The worker cannot start a process for the run.');
        }
        try {
            for ($written = 0; $written < \strlen($json);) {
                $bytes = @fwrite($pipes[0], substr($json, $written));
                if (false === $bytes || 0 === $bytes) {
                    throw new \RuntimeException('The process of the run closed its input.');
                }
                $written += $bytes;
            }
        } catch (\Throwable $e) {
            proc_terminate($process);
            throw $e;
        } finally {
            fclose($pipes[0]);
        }

        return new ProcessScriptChild($process);
    }
}
