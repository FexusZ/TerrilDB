<?php

declare(strict_types=1);

namespace App\Core\Diagnostics;

use App\Core\Connection\Engine;
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
     * Ce que chaque thème peut lire, sans lire les données.
     *
     * @return array<string, DiagnosticStatus>
     */
    public function capabilities(): array
    {
        return [
            'sessions' => $this->sessions?->status() ?? DiagnosticStatus::unsupported(),
            'locks' => $this->locks?->status() ?? DiagnosticStatus::unsupported(),
        ];
    }
}
