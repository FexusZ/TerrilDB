# API REST

Base : `/api/v1`. Authentification : `Authorization: Bearer <jeton>` uniquement (ni cookie, ni autre schéma).
Les jetons se créent dans l'interface (Sécurité du compte) : le texte du jeton n'est montré qu'une fois.

Un jeton n'ouvre que les connexions cochées à sa création, jusqu'à son expiration (365 jours au plus, ou aucune si le propriétaire choisit « Jamais » : le jeton vaut alors jusqu'à sa révocation).
Un jeton « toutes mes connexions » couvre celles du compte au moment de sa création ; le bouton « Actualiser » de
l'interface y ajoute ensuite les connexions créées depuis (et retire les supprimées) sans changer le texte du jeton,
donc sans rien reconfigurer côté client. Il est
supprimé quand le mot de passe du compte change. Par défaut un jeton ne fait que lire : une écriture sur `/query` est refusée par le filtre puis, en dernier recours, par le moteur
lui-même (transaction en lecture seule). Quatre droits se cochent à la création et ne changent plus (on émet un autre jeton) :
`writeData`, `writeSchema`, `createConnections` (voir plus bas) et `diagnostic` (supervision du serveur, lecture seule, voir « Diagnostics »).

| Méthode | Chemin | Rôle |
| --- | --- | --- |
| GET | `/api/v1/token` | le jeton courant et son compte |
| GET | `/api/v1/connections` | les connexions permises : `id`, `name`, `engine` |
| GET | `/api/v1/connections/{id}/tables` | tables et vues, avec une estimation du nombre de lignes |
| GET | `/api/v1/connections/{id}/tables/{name}` | colonnes, index, clés étrangères, définition d'une vue |
| POST | `/api/v1/connections/{id}/query` | exécute UNE instruction de lecture : `{"sql": "…", "maxRows": 1000, "timeoutMs": 30000}` ; `timeoutMs` ne peut pas dépasser le délai du proxy moins 20 s (100 000 ms par défaut, `TERRILDB_PROXY_TIMEOUT` : voir docs/deploy.md), sinon 422 `invalid_input` |
| POST | `/api/v1/connections/{id}/prepare` | (droit d'écriture) juge UNE instruction d'écriture SANS l'exécuter : `decision` (`allowed` / `needs_confirmation`), `kind`, `danger`, `reason` et un `confirmationToken` lié au texte exact, à la connexion et au jeton d'API, à usage unique, valable 300 s |
| POST | `/api/v1/connections/{id}/script/prepare` | (droit d'écriture) juge un SCRIPT (500 instructions / 1 Mo au plus) instruction par instruction avec les droits du jeton, sans rien exécuter : verdicts, `transactional`, `atomic`, `dryRun`, `warnings`, UN `confirmationToken` lié au texte exact ; une instruction refusée = 403 `script_refused` (`reason`, `statement`) et aucun jeton |
| POST | `/api/v1/connections/{id}/script/execute` | exécute le script préparé : `script`, `confirmationToken` (TOUJOURS obligatoire, même pour un jeton `singleStep`), `confirmed` (si destructif), `dryRun`, `timeoutMs` (global, 100 000 ms par défaut, au plus le délai du proxy moins 20 s : au-dessus, 422 `invalid_input` AVANT toute consommation du jeton ; voir docs/deploy.md). Arrêt à la première erreur ; réponse 200 avec `status` (`committed`, `dry_run`, `rolled_back`, `partial`, `failed`), `applied`, `failed`, `notRun`, `steps` ; audit : une ligne par instruction, `executionId` commun. Le jeton de confirmation est consommé par CHAQUE exécution, `dryRun` compris : tester puis exécuter pour de vrai demande deux `prepare`. Un `dryRun` est refusé (409 `dry_run_unavailable`, `reason: non_transactional_tables`) si le script écrit dans une table sans transaction (MyISAM, MEMORY…), et chaque lecture est limitée à 1000 lignes (`truncated`) |
| POST | `/api/v1/connections/{id}/execute` | exécute UNE instruction d'écriture (droit `writeData` et/ou `writeSchema`) : mêmes champs que `/query`, plus `confirmationToken` (obligatoire, voir ci-dessous) et `confirmed` |
| GET | `/api/v1/connections/{id}/diagnostics` | (droit `diagnostic`) ce que chaque source de supervision peut lire : `capabilities.sessions`, `.locks`, `.statements` et `.variables` = `{availability, reason, privilege}` |
| GET | `/api/v1/connections/{id}/diagnostics/sessions` | (droit `diagnostic`) les sessions en cours sur le serveur, lecture seule |
| GET | `/api/v1/connections/{id}/diagnostics/locks` | (droit `diagnostic`) transactions ouvertes et arbre « qui bloque qui », lecture seule |
| GET | `/api/v1/connections/{id}/diagnostics/queries?order=&limit=` | (droit `diagnostic`) les requêtes les plus coûteuses par empreinte, lecture seule |
| GET | `/api/v1/connections/{id}/diagnostics/variables` | (droit `diagnostic`) variables de configuration, lecture seule : `q` (morceau de nom), `changed=1`, `limit` (1 à 500, 100 par défaut) |
| POST | `/api/v1/connections` | enregistre une connexion (droit `createConnections`) : `name`, `engine`, `host`, `port?`, `database`, `username`, `password` |

Résultat d'une requête : `columns`, `rows`, `rowCount`, `truncated` (d'autres lignes existaient), `affectedRows`
(`null` pour une lecture), `durationMs`. Les entiers au-delà de 2^53 sont rendus en chaînes.

## Erreurs

Toujours `{"error": "<code>", …}`. Codes : `invalid_token` (401, en-tête `WWW-Authenticate: Bearer`), `not_found` (404,
connexion hors jeton comprise), `query_refused` (403, avec `reason`), `query_failed` (400, avec `message` du moteur),
`query_timeout` (408), `invalid_input` (422), `bad_request` (400), `too_many_attempts` (429, avec `Retry-After`),
`target_unreachable` (502), `permission_denied` (403, le jeton n'a pas le droit), `confirmation_required` (409, avec `reason`),
`deadlock`, `serialization_failure` et `lock_timeout` (409, `retryable`, jamais le message du moteur).

Motifs de refus (`reason`) : `empty_statement`, `multiple_statements`, `incomplete_sql`, `too_long`, `not_read_only`,
`forbidden_construct`, `unsupported_statement`, `write_not_permitted` (le jeton n'a pas le droit de cette sorte d'écriture).

## Verrous : `deadlock`, `serialization_failure` et `lock_timeout`

Classés d'après le CODE du moteur (MySQL/MariaDB 1213 et 1205, PostgreSQL SQLSTATE 40P01, 40001 et 55P03), jamais d'après son texte, pour
`/execute` comme pour les écritures de l'interface. `deadlock` : le moteur a annulé toute la transaction, l'opération entière se rejoue.
`lock_timeout` : l'instruction a cessé d'attendre un verrou tenu par une autre transaction ; on regarde qui bloque, puis on réessaie. Le
corps porte `retryable: true` ; TerrilDB ne rejoue jamais seul. Exception : une modification de structure MySQL/MariaDB/ClickHouse se joue
instruction par instruction ; si certaines sont DÉJÀ appliquées (`applied` non vide), `retryable` vaut `false` et il ne faut pas rejouer
l'opération entière : relire la table, puis refaire ce qui manque. `retryable: true` n'est rendu que si rien n'a été appliqué (transaction
annulée en entier). `serialization_failure` (SQLSTATE PostgreSQL 40001) : deux transactions simultanées ne pouvaient pas rester cohérentes, le moteur
a annulé celle-ci ; même conduite que `deadlock` (on rejoue l'opération entière), mais ce n'est pas un interblocage. Un délai d'exécution reste `query_timeout`. Les autres échecs d'écriture
(`data_change_failed`, `schema_change_failed`) portent `retryable: false`.

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

## Diagnostics

Le droit `diagnostic` (refusé par défaut, distinct de tout droit d'écriture) donne la supervision du serveur de la connexion, jamais par la
console : des requêtes de catalogue fixes, écrites par TerrilDB, en lecture seule (aucun `KILL`, `SET`, `ALTER`). Sans le droit : 403
`permission_denied`. Moteur sans supervision (Redis) : `capabilities` dit `unsupported`, la route des sessions répond 501 `unsupported_operation`.

Chaque source rend un **état** et non une erreur : `availability` = `available`, `unsupported`, `permission_denied` (avec `privilege`, le droit
manquant), `disabled` (source éteinte ou absente ; `reason` en donne le code stable) ou `error` (`reason: check_failed` : la vérification a échoué
pour une cause imprévue ; on ne sait pas, on le dit, et le détail n'est jamais montré). Dans la carte des capacités (`GET …/diagnostics`) un thème en échec rend
`error` sans masquer les autres ; la route d'un thème précis (`…/diagnostics/sessions`) répond alors 502 `diagnostics_failed`. Une liste vide d'une source illisible n'est jamais rendue comme
« personne ». Sessions : `id`, `user`, `client` (sans port), `database`, `state`, `seconds`, `query`, `queryReason`, `current` (la session de TerrilDB) ; 200 au
plus (`truncated`). Le texte de la requête est **masqué puis raccourci à 200 caractères** : les littéraux (chaînes, nombres) sont remplacés par `?` et les commentaires
retirés avant la troncature (d'après le scanner lexical du garde, par dialecte), donc `ALTER ROLE x PASSWORD 'secret'` ressort `ALTER ROLE x PASSWORD ?`. Un texte que
le scanner ne sait pas lire jusqu'au bout (coupé par le moteur au milieu d'un littéral) n'est pas rendu : `query` est nul et `queryReason` vaut `redacted`. Il ne figure jamais dans le journal d'audit de TerrilDB
(`diagnostic.read` note qui a lu quelle page, le nombre de lignes et l'état).

**Verrous** (`/diagnostics/locks`) : `transactions` (les plus anciennes d'abord : `session`, `user`, `state`, `seconds` = âge, `rowsLocked` et
`rowsModified` quand le moteur les donne, `sleeping` = la session n'exécute rien, `query`) et `blocking`, l'arbre « qui bloque qui » : chaque racine
bloque sans attendre, ses `blocked` attendent après elle ; chaque session n'apparaît qu'UNE fois, sous un seul bloqueur (le détenteur de préférence, sinon
le dernier devant elle dans la file), et l'arbre est borné à 500 sessions et 20 niveaux : `blockingTruncated` dit que des sessions manquent ; `query` suit la même
règle de masquage que les sessions (`queryReason`) ; pour chaque attente `seconds` (depuis quand), `kind` (`row` ou `metadata` : la file derrière un
DDL), `mode` et `object` tels que le moteur les nomme. Aucune instruction d'arrêt n'est calculée ni exécutée. « Aucune attente » (`available` et `blocking`
vide) se distingue de « non lisible » (`permission_denied`, `disabled` avec `reason: performance_schema_off`). MariaDB ne rapporte que les verrous de
lignes (ses verrous de métadonnées demandent `performance_schema`, éteint par défaut) ; ClickHouse et Redis : `unsupported`, route 501.

**Requêtes coûteuses** (`/diagnostics/queries?order=total_time|mean_time|calls&limit=1..100&hours=1..168`, 20 par défaut ; `hours` = fenêtre de temps (24 par défaut) lue par ClickHouse seulement et rendue dans `windowHours`, nulle ailleurs : les autres sources cumulent depuis leur remise à zéro ; `total_time` par défaut ; autre valeur :
422 `invalid_input`) : `digests`, une ligne par empreinte, avec `text` (normalisé par le moteur, PUIS littéraux masqués par `?` ici, car `pg_stat_statements` ne normalise pas les instructions utilitaires comme `ALTER ROLE … PASSWORD '…'` ; raccourci à 200 caractères ; nul avec `textReason: redacted` si le texte ne se laisse pas lire sans risque),
`database`, `calls`, `totalMs` / `meanMs` / `maxMs` (TOUJOURS en millisecondes : `performance_schema` compte en picosecondes, converties côté serveur),
`rowsExamined` / `rowsReturned` / `ratio` (examinées pour une renvoyée ; nuls quand la source ne compte pas les lignes lues, c'est le cas de
`pg_stat_statements`), `noIndexUsed`, `tmpDiskTables`, `sortMergePasses` (MySQL/MariaDB), `firstSeen` / `lastSeen` (ISO 8601 UTC, selon la source). Le classement par temps
TOTAL est le bon défaut (fréquence x durée). Sources : MySQL/MariaDB `performance_schema.events_statements_summary_by_digest` (éteint = `disabled`
`performance_schema_off`, MariaDB le livre éteint) ; PostgreSQL `pg_stat_statements`, base de la connexion seulement (`disabled` `extension_missing` ou
`library_not_loaded` : il faut `shared_preload_libraries` PUIS `CREATE EXTENSION`, TerrilDB n'exécute ni l'un ni l'autre) ; ClickHouse `system.query_log`
(requêtes terminées, regroupées par `normalized_query_hash`, tables système exclues ; absent = `disabled` `query_log_off`, écrit par lots : les toutes dernières
requêtes peuvent manquer) ; Redis : `unsupported` (route 501). Rien n'est remis à zéro (TRUNCATE du résumé, `pg_stat_statements_reset`) : c'est une écriture, à faire à la main. Une
liste vide n'est rendue que si la source est lisible. Audit `diagnostic.read` : `topic: top_queries`, `order`, `limit`, `status`, `rows`, jamais un texte.

**Variables** (`/diagnostics/variables`) : `variables` classées par nom (à l'octet) avec `value`, `default` (quand le moteur le donne), `changed`
(diffère du défaut), `source` (origine de la valeur telle que le moteur la dit), `restart` (`required` : le serveur ne la relit qu'au démarrage ;
`pending` : valeur posée mais pas encore prise ; absent = le moteur ne le dit pas), `scope`, `unit`. Le nom cherché (`q`) est un paramètre lié, sans
casse, `_` et `%` littéraux ; hors bornes ou valeur illisible : 422 `invalid_input`. **Un nom qui évoque un mot de passe, un secret, un jeton ou une
clé (liste fermée) est rendu `masked: true` avec `value` et `default` nuls**, quel que soit le moteur. « Diffère du défaut » : MariaDB = origine autre que
`COMPILE-TIME` ; MySQL (qui ne donne pas de défaut, `default` nul) = origine de `variables_info` autre que `COMPILED` ; PostgreSQL = origine autre que
`default`/`override` (`boot_val` n'est qu'un repère) ; ClickHouse = `changed` de `system.settings` / `system.server_settings` (portées `session` et
`server` ; la lecture en `readonly=1` de TerrilDB y compte elle-même). MySQL : `performance_schema` éteint = `disabled`. ClickHouse : droit
`SELECT ON system.server_settings`. Redis : `unsupported` (501). Rien n'est modifié (ni `SET`, ni `SET PERSIST`) ; l'audit note le thème, l'état, le
nombre de lignes et le filtre « modifiées », jamais une valeur ni le nom cherché.

Compte de supervision minimal à donner à la connexion (documenté, pas imposé : sans le droit, l'état le dit) :

| Moteur | Source | Droit |
| --- | --- | --- |
| MySQL | `performance_schema.processlist` (`information_schema.PROCESSLIST` est déprécié) ; état `disabled` (`performance_schema_off`) si `performance_schema` est désactivé : la table se lit alors VIDE | `PROCESS` (accordé au compte lui-même : un droit reçu par un rôle n'est pas vu) |
| MariaDB | `information_schema.PROCESSLIST` (sa seule source) | `PROCESS`, comme ci-dessus |
| PostgreSQL | `pg_stat_activity` | rôle `pg_monitor` ou `pg_read_all_stats` |
| ClickHouse | `system.processes` | `SELECT ON system.processes` |
| Redis | – | non pris en charge |

Requêtes coûteuses : MySQL/MariaDB `SELECT` sur `performance_schema` ; PostgreSQL `pg_monitor` ou `pg_read_all_stats` (sans lui le serveur masque le texte des
requêtes des autres) ; ClickHouse `SELECT ON system.query_log` ; Redis, sans objet.
Variables : MySQL et MariaDB, aucun droit particulier (`SELECT` sur `performance_schema` implicite pour MySQL) ; PostgreSQL, lisible par tout compte, mais les
valeurs réservées (chemins, commandes) ne sont lues qu'avec `pg_read_all_settings` (inclus dans `pg_monitor`) ; ClickHouse `SELECT ON system.server_settings`.

Verrous et transactions : MySQL `PROCESS` + `SELECT` sur `performance_schema` + `SELECT` et `EXECUTE` sur `sys` (verrous de métadonnées) ; MariaDB `PROCESS` ;
PostgreSQL `pg_monitor` ; ClickHouse et Redis, sans objet.

## Limites

120 requêtes par minute et par jeton ; 30 échecs d'authentification par quart d'heure et par adresse.

## Exemple

```bash
curl -s -H "Authorization: Bearer $TERRILDB_TOKEN" -H 'Content-Type: application/json' \
  -d '{"sql": "select id, name from categories order by id", "maxRows": 10}' \
  http://localhost:8000/api/v1/connections/$CONNECTION_ID/query
```
