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
        private ?HealthDiagnostics $health = null,
        private ?AccountDiagnostics $accounts = null,
        private ?EncryptionDiagnostics $encryption = null,
        private ?ReplicationDiagnostics $replication = null,
        private ?LogDiagnostics $logs = null,
        private ?TabletDiagnostics $tablets = null,
        private ?MigrationDiagnostics $migrations = null,
        /** Pourquoi aucun thème n'est servi, quand le moteur le sait (code stable, ex. `vitess_tablet_scope`) ; sinon `not_supported`. */
        private ?string $unsupportedReason = null,
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
    public function accounts(): AccountDiagnostics
    {
        return $this->accounts ?? throw UnsupportedOperationException::for($this->engine, 'accounts');
    }

    /**
     * @throws UnsupportedOperationException
     */
    public function variables(): VariableDiagnostics
    {
        return $this->variables ?? throw UnsupportedOperationException::for($this->engine, 'variables');
    }

    /**
     * @throws UnsupportedOperationException
     */
    public function encryption(): EncryptionDiagnostics
    {
        return $this->encryption ?? throw UnsupportedOperationException::for($this->engine, 'encryption');
    }

    /**
     * @throws UnsupportedOperationException
     */
    public function replication(): ReplicationDiagnostics
    {
        return $this->replication ?? throw UnsupportedOperationException::for($this->engine, 'replication');
    }

    /**
     * @throws UnsupportedOperationException
     */
    public function health(): HealthDiagnostics
    {
        return $this->health ?? throw UnsupportedOperationException::for($this->engine, 'health');
    }

    /**
     * @throws UnsupportedOperationException
     */
    public function logs(): LogDiagnostics
    {
        return $this->logs ?? throw UnsupportedOperationException::for($this->engine, 'logs');
    }

    /**
     * @throws UnsupportedOperationException
     */
    public function tablets(): TabletDiagnostics
    {
        return $this->tablets ?? throw UnsupportedOperationException::for($this->engine, 'tablets');
    }

    /**
     * @throws UnsupportedOperationException
     */
    public function migrations(): MigrationDiagnostics
    {
        return $this->migrations ?? throw UnsupportedOperationException::for($this->engine, 'migrations');
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
        $themes = ['sessions' => $this->sessions, 'locks' => $this->locks, 'statements' => $this->statements, 'variables' => $this->variables, 'accounts' => $this->accounts, 'encryption' => $this->encryption, 'health' => $this->health, 'replication' => $this->replication, 'logs' => $this->logs];
        if (null !== $this->tablets) {
            // Annoncé seulement quand le serveur le sert (Vitess) : aucun autre moteur n'a cette notion, pas de ligne « non pris en charge ».
            $themes['tablets'] = $this->tablets;
        }
        if (null !== $this->migrations) {
            $themes['migrations'] = $this->migrations;
        }
        $capabilities = [];
        foreach ($themes as $name => $theme) {
            if (null === $theme) {
                $capabilities[$name] = DiagnosticStatus::unsupported($this->unsupportedReason ?? 'not_supported');

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
