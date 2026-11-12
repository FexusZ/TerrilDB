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
| POST | `/api/v1/connections/{id}/query` | exécute UNE instruction de lecture : `{"sql": "…", "maxRows": 1000, "timeoutMs": 30000}` |
| POST | `/api/v1/connections/{id}/execute` | exécute UNE instruction d'écriture (droit `writeData` et/ou `writeSchema`) : mêmes champs que `/query`, plus `confirmed` |
| GET | `/api/v1/connections/{id}/diagnostics` | (droit `diagnostic`) ce que chaque source de supervision peut lire : `capabilities.sessions` et `capabilities.locks` = `{availability, reason, privilege}` |
| GET | `/api/v1/connections/{id}/diagnostics/sessions` | (droit `diagnostic`) les sessions en cours sur le serveur, lecture seule |
| GET | `/api/v1/connections/{id}/diagnostics/locks` | (droit `diagnostic`) transactions ouvertes et arbre « qui bloque qui », lecture seule |
| GET | `/api/v1/connections/{id}/diagnostics/health?seconds=5` | (droit `diagnostic`) santé du serveur sur un intervalle de 1 à 30 s (5 par défaut) : la requête dure l'intervalle, lecture seule |
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
manquant) ou `disabled` (source éteinte ; `reason` en donne le code stable). Une liste vide d'une source illisible n'est jamais rendue comme
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

### Santé du serveur (`/diagnostics/health`, outil MCP `server_health`)

Les compteurs de MySQL et de MariaDB (`SHOW GLOBAL STATUS`) sont **cumulés depuis le démarrage** : un compteur seul ne prouve rien. TerrilDB prend donc
DEUX relevés à `seconds` secondes d'écart (entier de 1 à 30, 5 par défaut, sinon 422 `invalid_input`) et ne rapporte que des écarts, jamais un taux calculé sur un
relevé unique. L'attente se fait dans la requête (pas de boucle, rien n'est conservé : pas de série temporelle) ; sans le droit de lecture, aucun relevé n'est
pris. Les taux se calculent sur la durée RÉELLE entre les relevés (`seconds` de la réponse). Un serveur qui a redémarré (`Uptime` qui diminue) ou dont les statistiques ont été
remises à zéro (`stats_reset` de PostgreSQL qui change) donne `discarded: counters_reset` et aucun indicateur ; un compteur seul qui repart à zéro, absent de cette série ou
non numérique donne un indicateur `unknown` (`value` nul), jamais zéro. Réponse : `status`, `seconds`, `startedAt`, `endedAt`, `discarded`, `indicators`
(`key`, `value`, `unit` = `per_second`, `percent` ou `count`, `level` = `ok`, `warning` ou `unknown`, `threshold`).

Les seuils d'avertissement sont des REPÈRES à valider sur une charge représentative, pas des optimums ; un pourcentage n'est jugé que si l'intervalle a assez d'activité.
MySQL / MariaDB : `queries_per_second` (Questions), `connections_used_percent` (`Threads_connected` / `max_connections`, 80 %), `threads_running` (50),
`aborted_connects_percent` (5 %, dès 10 tentatives), `buffer_pool_hit_percent` (sous 99 %, dès 1000 demandes), `tmp_disk_tables_percent` (25 %, dès 100 tables),
`row_lock_waits_per_second` (avertissement si l'attente moyenne atteint 1 s), `slow_queries_per_second` (1), `redo_log_waits` (au moins 1). PostgreSQL (`pg_stat_database`
de la base de la connexion) : `transactions_per_second`, `rollback_percent` (10 %, dès 100 transactions), `cache_hit_percent` (sous 99 %, dès 1000 blocs),
`connections_used_percent` (80 %), `temp_files_per_second` (1), `deadlocks` (au moins 1), `rows_returned_per_second`. Aucun droit particulier n'est attendu (un refus du
serveur sur la lecture des compteurs est rendu `permission_denied`). PostgreSQL publie les statistiques d'une session avec un léger retard (de l'ordre de la seconde, jusqu'à
10 s pour une session inactive) : sur un intervalle très court, une activité récente peut ne pas encore y figurer. ClickHouse et Redis : `unsupported`, route 501. À
suivre (non livré) : longueur de l'historique d'annulation InnoDB, journaux (requêtes lentes, journal général, journal d'erreurs SQL), préparation à la reprise. L'audit
(`diagnostic.read`, `topic: health`) garde l'état, l'intervalle demandé et si la mesure a été écartée, jamais un compteur. Un délai maximal de requête côté serveur web inférieur
à l'intervalle choisi (plus 2 s) interrompt la mesure : laissez la marge.

Compte de supervision minimal à donner à la connexion (documenté, pas imposé : sans le droit, l'état le dit) :

| Moteur | Source | Droit |
| --- | --- | --- |
| MySQL | `performance_schema.processlist` (`information_schema.PROCESSLIST` est déprécié) ; état `disabled` (`performance_schema_off`) si `performance_schema` est désactivé : la table se lit alors VIDE | `PROCESS` (accordé au compte lui-même : un droit reçu par un rôle n'est pas vu) |
| MariaDB | `information_schema.PROCESSLIST` (sa seule source) | `PROCESS`, comme ci-dessus |
| PostgreSQL | `pg_stat_activity` | rôle `pg_monitor` ou `pg_read_all_stats` |
| ClickHouse | `system.processes` | `SELECT ON system.processes` |
| Redis | – | non pris en charge |

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
