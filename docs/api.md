# API REST

Base : `/api/v1`. Authentification : `Authorization: Bearer <jeton>` uniquement (ni cookie, ni autre schéma).
Les jetons se créent dans l'interface (Sécurité du compte) : le texte du jeton n'est montré qu'une fois.

Un jeton n'ouvre que les connexions cochées à sa création, jusqu'à son expiration (365 jours au plus, ou aucune si le propriétaire choisit « Jamais » : le jeton vaut alors jusqu'à sa révocation).
Un jeton « toutes mes connexions » couvre celles du compte au moment de sa création ; le bouton « Actualiser » de
l'interface y ajoute ensuite les connexions créées depuis (et retire les supprimées) sans changer le texte du jeton,
donc sans rien reconfigurer côté client. Il est
supprimé quand le mot de passe du compte change. Par défaut un jeton ne fait que lire : une écriture sur `/query` est refusée par le filtre puis, en dernier recours, par le moteur
lui-même (transaction en lecture seule). Trois droits se cochent à la création et ne changent plus (on émet un autre jeton) :
`writeData`, `writeSchema`, `createConnections` (voir plus bas).

| Méthode | Chemin | Rôle |
| --- | --- | --- |
| GET | `/api/v1/token` | le jeton courant et son compte |
| GET | `/api/v1/connections` | les connexions permises : `id`, `name`, `engine` |
| GET | `/api/v1/connections/{id}/tables` | tables et vues, avec une estimation du nombre de lignes |
| GET | `/api/v1/connections/{id}/tables/{name}` | colonnes, index, clés étrangères, définition d'une vue |
| POST | `/api/v1/connections/{id}/query` | exécute UNE instruction de lecture : `{"sql": "…", "maxRows": 1000, "timeoutMs": 30000}` |
| POST | `/api/v1/connections/{id}/prepare` | (droit d'écriture) juge UNE instruction d'écriture SANS l'exécuter : `decision` (`allowed` / `needs_confirmation`), `kind`, `danger`, `reason` et un `confirmationToken` lié au texte exact, à la connexion et au jeton d'API, à usage unique, valable 300 s |
| POST | `/api/v1/connections/{id}/script/prepare` | (droit d'écriture) juge un SCRIPT (500 instructions / 1 Mo au plus) instruction par instruction avec les droits du jeton, sans rien exécuter : verdicts, `transactional`, `atomic`, `dryRun`, `warnings`, UN `confirmationToken` lié au texte exact ; une instruction refusée = 403 `script_refused` (`reason`, `statement`) et aucun jeton |
| POST | `/api/v1/connections/{id}/script/execute` | exécute le script préparé : `script`, `confirmationToken` (TOUJOURS obligatoire, même pour un jeton `singleStep`), `confirmed` (si destructif), `dryRun`, `timeoutMs` (global). Arrêt à la première erreur ; réponse 200 avec `status` (`committed`, `dry_run`, `rolled_back`, `partial`, `failed`), `applied`, `failed`, `notRun`, `steps` ; audit : une ligne par instruction, `executionId` commun. Le jeton de confirmation est consommé par CHAQUE exécution, `dryRun` compris : tester puis exécuter pour de vrai demande deux `prepare`. Un `dryRun` est refusé (409 `dry_run_unavailable`, `reason: non_transactional_tables`) si le script écrit dans une table sans transaction (MyISAM, MEMORY…), et chaque lecture est limitée à 1000 lignes (`truncated`) |
| POST | `/api/v1/connections/{id}/execute` | exécute UNE instruction d'écriture (droit `writeData` et/ou `writeSchema`) : mêmes champs que `/query`, plus `confirmationToken` (obligatoire, voir ci-dessous) et `confirmed` |
| POST | `/api/v1/connections` | enregistre une connexion (droit `createConnections`) : `name`, `engine`, `host`, `port?`, `database`, `username`, `password` |

Résultat d'une requête : `columns`, `rows`, `rowCount`, `truncated` (d'autres lignes existaient), `affectedRows`
(`null` pour une lecture), `durationMs`. Les entiers au-delà de 2^53 sont rendus en chaînes.

## Erreurs

Toujours `{"error": "<code>", …}`. Codes : `invalid_token` (401, en-tête `WWW-Authenticate: Bearer`), `not_found` (404,
connexion hors jeton comprise), `query_refused` (403, avec `reason`), `query_failed` (400, avec `message` du moteur),
`query_timeout` (408), `invalid_input` (422), `bad_request` (400), `too_many_attempts` (429, avec `Retry-After`),
`target_unreachable` (502), `permission_denied` (403, le jeton n'a pas le droit), `confirmation_required` (409, avec `reason`).

Motifs de refus (`reason`) : `empty_statement`, `multiple_statements`, `incomplete_sql`, `too_long`, `not_read_only`,
`forbidden_construct`, `unsupported_statement`, `write_not_permitted` (le jeton n'a pas le droit de cette sorte d'écriture).

## Écrire

**Deux temps par défaut** : sans `confirmationToken` valide, `/execute` ne part pas (409 `preparation_required`). Un jeton rejoué, expiré,
inconnu ou présenté pour un texte qui diffère d'un octet (ou une autre connexion, un autre jeton d'API, un autre verdict) est refusé
(403 `invalid_confirmation`) et brûlé. Un destructif exige en plus `confirmed: true` (409 `confirmation_required` sans consommer le jeton).
Un jeton d'API créé avec `singleStep: true` (choix du propriétaire, refusé par défaut) garde l'écriture directe ; un `confirmationToken`
présenté est alors tout de même vérifié. Chaque préparation (`query.prepared`) et chaque rejet est journalisé, jamais le jeton.
Limite : l'aperçu ne donne ni tables touchées ni lignes estimées.

`/execute` n'accepte que ce que les droits du jeton permettent : `writeData` = INSERT, UPDATE, DELETE ; `writeSchema` = CREATE, ALTER,
DROP, TRUNCATE, GRANT, CALL… Le refus faute de droit est définitif. Une instruction destructrice (UPDATE ou DELETE sans WHERE, DROP,
TRUNCATE…) répond 409 `confirmation_required` : la renvoyer avec `"confirmed": true` seulement si c'est voulu. L'écriture est validée si
elle réussit. Attention : la distinction données/structure est faite par un filtre lexical, sans transaction en lecture seule du moteur
pour la tenir ; limitez aussi les droits du compte de la base elle-même.

Une connexion créée par jeton est chiffrée par la clé du jeton, rejoint aussitôt la liste de ce jeton, et n'est visible des autres jetons
« toutes mes connexions » qu'à leur actualisation. Une adresse qui désigne le serveur de TerrilDB lui-même (boucle locale, lien local, base interne) répond 403 `forbidden_target` sauf si le
compte est administrateur, comme dans l'interface web ; les autres machines, réseaux privés compris, restent permises.

## Limites

120 requêtes par minute et par jeton ; 30 échecs d'authentification par quart d'heure et par adresse.

## Exemple

```bash
curl -s -H "Authorization: Bearer $TERRILDB_TOKEN" -H 'Content-Type: application/json' \
  -d '{"sql": "select id, name from categories order by id", "maxRows": 10}' \
  http://localhost:8000/api/v1/connections/$CONNECTION_ID/query
```
