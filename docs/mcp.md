# Serveur MCP

Adresse : `POST /mcp` (transport HTTP « streamable »), avec `Authorization: Bearer <jeton>`. C'est le même jeton que
pour l'API REST (voir `docs/api.md`) : il n'ouvre que les connexions choisies à sa création, expire (sauf choix contraire), et disparaît au
changement de mot de passe. Chaque requête est authentifiée par le jeton, session MCP ou non ; une session MCP est liée
au jeton qui l'a ouverte (un autre jeton ne peut pas la reprendre).

Seuls `POST` (messages JSON-RPC) et `DELETE` (fin de session) sont servis. Il n'y a pas de flux serveur ouvert (`GET`
répond 405) : PHP ne peut pas tenir une connexion entre deux requêtes, et le protocole prévoit que le serveur s'en
passe. Les noms d'hôte acceptés se règlent avec `TERRILDB_ALLOWED_HOSTS` (liste séparée par des virgules, `localhost`,
`127.0.0.1` et `[::1]` par défaut) : une requête dont l'en-tête `Host` n'y figure pas reçoit un 403.

## Outils

Les quatre premiers sont en lecture seule (`readOnlyHint`), sur les connexions du jeton uniquement. Les autres n'existent que pour
les jetons qui ont reçu le droit correspondant (un jeton ordinaire ne les voit pas).

| Outil | Arguments | Rôle |
| --- | --- | --- |
| `list_connections` | – | `id`, `name`, `engine` des connexions permises |
| `list_tables` | `connection` | tables et vues, nombre de lignes estimé |
| `describe_table` | `connection`, `table` | colonnes (types natifs), index, clés étrangères, définition d'une vue |
| `run_query` | `connection`, `sql`, `maxRows?`, `timeoutMs?` | UNE instruction de lecture ; toute écriture est refusée, même pour un jeton qui écrit |
| `list_sessions` | `connection` | (droit `diagnostic`, lecture seule) sessions en cours sur le serveur : utilisateur, base, état, durée, début de la requête (littéraux masqués par `?`, 200 caractères au plus ; nul avec `queryReason: redacted` si le texte ne peut pas être masqué sans risque) ; l'état dit si le compte de la base n'a pas le droit de les lire (voir docs/api.md, « Diagnostics ») |
| `list_variables` | `connection`, `search?`, `changedOnly?`, `limit?` | (droit `diagnostic`, lecture seule) variables de configuration : valeur, défaut quand le moteur le donne, écart au défaut, origine, redémarrage nécessaire ; la valeur d'un nom évoquant un secret n'est jamais donnée (`masked`) ; rien n'est modifié |
| `list_locks` | `connection` | (droit `diagnostic`, lecture seule) transactions ouvertes (les plus anciennes d'abord) et arbre de blocage : qui attend qui, depuis combien de temps, sur quel objet ; rien n'est arrêté ; l'état distingue « aucun verrou » de « non lisible » (voir docs/api.md, « Diagnostics ») |
| `top_queries` | `connection`, `order` (`total_time`, `mean_time`, `calls`), `limit` (1 à 100), `hours` (1 à 168, 24 par défaut, ClickHouse seulement) | (droit `diagnostic`, lecture seule) requêtes les plus coûteuses par empreinte : texte normalisé et littéraux masqués par `?` (aussi pour les instructions utilitaires de PostgreSQL, que `pg_stat_statements` ne normalise pas ; nul avec `textReason: redacted` si illisible), appels, temps total / moyen / maximal en millisecondes, lignes examinées et renvoyées quand le moteur les donne ; rien n'est remis à zéro ; l'état distingue « source éteinte » (performance_schema, pg_stat_statements, query_log) et « droits insuffisants » de « aucune requête » (voir docs/api.md, « Diagnostics ») |
| `execute_statement` | `connection`, `sql`, `confirmed?`, `maxRows?`, `timeoutMs?` | (droit `writeData`/`writeSchema`) UNE instruction d'écriture dans les droits du jeton ; un destructif répond `confirmation_required` tant que `confirmed` n'est pas vrai |
| `create_connection` | `name`, `engine`, `host`, `port?`, `database`, `username`, `password` | (droit `createConnections`) enregistre une connexion, qui rejoint aussitôt le jeton ; le mot de passe n'est jamais rendu |

Une erreur est rendue comme erreur d'outil (`isError`) avec un code stable en JSON : `not_found` (connexion hors jeton
comprise), `query_refused` (avec `reason`), `query_failed` (message du moteur), `query_timeout`, `deadlock` / `serialization_failure` / `lock_timeout` (`retryable: true`, voir docs/api.md), `invalid_input`,
`target_unreachable`, `permission_denied`, `confirmation_required` (avec `reason`) ; le motif `write_not_permitted` signale un droit manquant.

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

## Brancher un assistant

Exemple avec Claude Code ; la démarche est la même pour un autre client qui accepte un serveur HTTP avec en-têtes.

1. **Créer le jeton** : Sécurité du compte → Jetons d'API. Commencer par un jeton en lecture seule, limité aux connexions utiles.
2. **Le poser dans une variable d'environnement**, par exemple `TERRILDB_TOKEN`, plutôt que dans un fichier de configuration
   (profil du shell, gestionnaire de secrets). Le jeton ne s'affiche qu'une fois, à sa création.
3. **Déclarer le serveur** :

   ```bash
   claude mcp add --transport http --scope user terrildb https://terril.example/mcp \
     --header "Authorization: Bearer $TERRILDB_TOKEN"
   ```

   Pour un dépôt d'équipe, un `.mcp.json` versionné ne contient aucun secret si l'en-tête cite la variable :

   ```json
   {
     "mcpServers": {
       "terrildb": {
         "type": "http",
         "url": "https://terril.example/mcp",
         "headers": { "Authorization": "Bearer ${TERRILDB_TOKEN}" }
       }
     }
   }
   ```

   Avec `claude mcp add`, le shell remplace `$TERRILDB_TOKEN` avant l'enregistrement : la valeur est alors écrite dans la
   configuration de Claude Code (portée `user`, hors dépôt). Pour qu'elle n'y soit pas, passer par le `.mcp.json` ci-dessus.
4. **Vérifier** : la commande `/mcp` de Claude Code doit afficher `terrildb` comme connecté.
5. **Dépannage** :
   - `401` : la variable est absente de l'environnement du processus qui lance l'assistant, ou le jeton a expiré, a été
     révoqué ou a disparu au changement de mot de passe ;
   - `403` : le nom d'hôte de l'adresse n'est pas dans `TERRILDB_ALLOWED_HOSTS` ;
   - une connexion manque dans `list_connections` : le jeton ne la couvre pas (un jeton « toutes mes connexions » doit être
     actualisé depuis l'interface après l'ajout d'une connexion).

### Précautions

- **Jetons d'écriture** : prendre un jeton séparé, à durée courte, et exiger une approbation côté client pour
  `execute_statement` (règle de permission ou hook de l'assistant). TerrilDB ne demande de confirmation que pour les
  instructions destructrices ; une écriture ordinaire part du premier coup (voir #41).
- **Données non fiables** : ce que le serveur renvoie à l'assistant (noms de tables, contenus des lignes, messages du
  moteur) est de la donnée, jamais une consigne. Une ligne de table peut contenir du texte qui cherche à diriger
  l'assistant : un jeton en lecture seule limite ce qu'un tel texte peut obtenir.
- **Serveur de développement** : `php -S` ne traite qu'une requête à la fois ; une requête longue de l'assistant fige alors
  l'interface. La production (FrankenPHP) n'a pas cette limite.
