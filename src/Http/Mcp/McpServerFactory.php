<?php

declare(strict_types=1);

namespace App\Http\Mcp;

use Mcp\Schema\ToolAnnotations;
use Mcp\Server;
use Mcp\Server\Session\FileSessionStore;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Construit le serveur MCP d'une requête. PHP ne garde rien entre deux requêtes : le serveur est rebâti à chaque appel
 * (quelques outils, sans découverte par parcours de fichiers) et seul l'état du protocole est conservé, dans des fichiers.
 */
final readonly class McpServerFactory
{
    private const INSTRUCTIONS = 'TerrilDB donne un accès en lecture seule à des bases de données. Commencez par list_connections, puis list_tables et describe_table pour comprendre le schéma avant d’écrire une requête avec run_query. Une seule instruction à la fois ; toute écriture est refusée.';

    public function __construct(
        private McpTools $tools,
        #[Autowire('%kernel.cache_dir%/mcp-sessions')] private string $sessionDirectory,
    ) {
    }

    public function create(): Server
    {
        $readOnly = new ToolAnnotations(readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: true);
        $connection = ['type' => 'string', 'description' => 'Identifiant (UUID) d’une connexion rendu par list_connections.'];

        return Server::builder()
            ->setServerInfo('TerrilDB', '1.0.0')
            ->setInstructions(self::INSTRUCTIONS)
            ->setSession(new FileSessionStore($this->sessionDirectory))
            ->addTool(
                [$this->tools, 'listConnections'],
                name: 'list_connections',
                description: 'Liste les connexions de base de données que ce jeton peut interroger (id, nom, moteur).',
                annotations: $readOnly,
                inputSchema: ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false],
            )
            ->addTool(
                [$this->tools, 'listTables'],
                name: 'list_tables',
                description: 'Liste les tables et vues d’une connexion, avec une estimation du nombre de lignes (jamais un comptage exact).',
                annotations: $readOnly,
                inputSchema: ['type' => 'object', 'properties' => ['connection' => $connection], 'required' => ['connection'], 'additionalProperties' => false],
            )
            ->addTool(
                [$this->tools, 'describeTable'],
                name: 'describe_table',
                description: 'Décrit une table ou une vue : colonnes et types natifs, index, clés étrangères, définition d’une vue.',
                annotations: $readOnly,
                inputSchema: ['type' => 'object', 'properties' => ['connection' => $connection, 'table' => ['type' => 'string', 'description' => 'Nom exact de la table ou de la vue.']], 'required' => ['connection', 'table'], 'additionalProperties' => false],
            )
            ->addTool(
                [$this->tools, 'runQuery'],
                name: 'run_query',
                description: 'Exécute UNE instruction SQL en lecture seule (SELECT, WITH, SHOW, EXPLAIN…). Toute écriture, tout accès aux fichiers du serveur et toute seconde instruction sont refusés. Le résultat est limité à maxRows lignes.',
                annotations: $readOnly,
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'connection' => $connection,
                        'sql' => ['type' => 'string', 'description' => 'Une instruction SQL de lecture, dans le dialecte du moteur de la connexion.'],
                        'maxRows' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100000, 'description' => 'Nombre maximal de lignes rendues (1000 par défaut).'],
                        'timeoutMs' => ['type' => 'integer', 'minimum' => 100, 'maximum' => 600000, 'description' => 'Durée maximale en millisecondes (30000 par défaut).'],
                    ],
                    'required' => ['connection', 'sql'],
                    'additionalProperties' => false,
                ],
            )
            ->build();
    }
}
