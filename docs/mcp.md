# Serveur MCP (lecture seule)

Adresse : `POST /mcp` (transport HTTP « streamable »), avec `Authorization: Bearer <jeton>`. C'est le même jeton que
pour l'API REST (voir `docs/api.md`) : il n'ouvre que les connexions choisies à sa création, expire (sauf choix contraire), et disparaît au
changement de mot de passe. Chaque requête est authentifiée par le jeton, session MCP ou non ; une session MCP est liée
au jeton qui l'a ouverte (un autre jeton ne peut pas la reprendre).

Seuls `POST` (messages JSON-RPC) et `DELETE` (fin de session) sont servis. Il n'y a pas de flux serveur ouvert (`GET`
répond 405) : PHP ne peut pas tenir une connexion entre deux requêtes, et le protocole prévoit que le serveur s'en
passe. Les noms d'hôte acceptés se règlent avec `TERRILDB_ALLOWED_HOSTS` (liste séparée par des virgules, `localhost`,
`127.0.0.1` et `[::1]` par défaut) : une requête dont l'en-tête `Host` n'y figure pas reçoit un 403.

## Outils

Tous en lecture seule (`readOnlyHint`), sur les connexions du jeton uniquement.

| Outil | Arguments | Rôle |
| --- | --- | --- |
| `list_connections` | – | `id`, `name`, `engine` des connexions permises |
| `list_tables` | `connection` | tables et vues, nombre de lignes estimé |
| `describe_table` | `connection`, `table` | colonnes (types natifs), index, clés étrangères, définition d'une vue |
| `run_query` | `connection`, `sql`, `maxRows?`, `timeoutMs?` | UNE instruction de lecture ; toute écriture est refusée |

Une erreur est rendue comme erreur d'outil (`isError`) avec un code stable en JSON : `not_found` (connexion hors jeton
comprise), `query_refused` (avec `reason`), `query_failed` (message du moteur), `query_timeout`, `invalid_input`,
`target_unreachable`.

## Configuration d'un client

Exemple pour un client qui accepte un serveur HTTP avec en-têtes :

```json
{
  "mcpServers": {
    "terrildb": {
      "type": "http",
      "url": "https://terril.example/mcp",
      "headers": { "Authorization": "Bearer tdb_…" }
    }
  }
}
```
