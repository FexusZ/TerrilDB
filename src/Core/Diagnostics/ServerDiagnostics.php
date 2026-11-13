<?php

declare(strict_types=1);

namespace App\Core\Diagnostics;

use App\Core\Connection\Engine;
use App\Core\Diagnostics\Exception\DiagnosticsFailedException;
use App\Core\Driver\Exception\UnsupportedOperationException;

/**
 * Les diagnostics d'un serveur cible, une petite interface par thème : un moteur n'implémente que ce qu'il sait.
 * Rien ne s'écrit par cette voie (ni KILL, ni SET) : au plus un texte à relire, jamais exécuté.
 */
final readonly class ServerDiagnostics
{
    public function __construct(
        private Engine $engine,
        private ?SessionDiagnostics $sessions = null,
        private ?LockDiagnostics $locks = null,
        private ?StatementDiagnostics $statements = null,
        private ?VariableDiagnostics $variables = null,
    ) {
    }

    /**
     * @throws UnsupportedOperationException
     */
    public function sessions(): SessionDiagnostics
    {
        return $this->sessions ?? throw UnsupportedOperationException::for($this->engine, 'sessions');
    }

    /**
     * @throws UnsupportedOperationException
     */
    public function locks(): LockDiagnostics
    {
        return $this->locks ?? throw UnsupportedOperationException::for($this->engine, 'locks');
    }

    /**
     * @throws UnsupportedOperationException
     */
    public function statements(): StatementDiagnostics
    {
        return $this->statements ?? throw UnsupportedOperationException::for($this->engine, 'statements');
    }

    /**
     * @throws UnsupportedOperationException
     */
    public function variables(): VariableDiagnostics
    {
        return $this->variables ?? throw UnsupportedOperationException::for($this->engine, 'variables');
    }

    /**
     * Ce que chaque thème peut lire, sans lire les données. Un thème dont la vérification échoue pour une cause imprévue rend
     * l'état « erreur » sans masquer les autres ; `$onFailure` reçoit son nom et l'exception (pour la consigner, jamais la montrer).
     *
     * @param \Closure(string, DiagnosticsFailedException): void|null $onFailure
     *
     * @return array<string, DiagnosticStatus>
     */
    public function capabilities(?\Closure $onFailure = null): array
    {
        $themes = ['sessions' => $this->sessions, 'locks' => $this->locks, 'statements' => $this->statements, 'variables' => $this->variables];
        $capabilities = [];
        foreach ($themes as $name => $theme) {
            if (null === $theme) {
                $capabilities[$name] = DiagnosticStatus::unsupported();

                continue;
            }
            try {
                $capabilities[$name] = $theme->status();
            } catch (DiagnosticsFailedException $e) {
                null === $onFailure || $onFailure($name, $e);
                $capabilities[$name] = DiagnosticStatus::failed();
            }
        }

        return $capabilities;
    }
}
