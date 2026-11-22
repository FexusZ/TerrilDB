# API REST

Base : `/api/v1`. Authentification : `Authorization: Bearer <jeton>` uniquement (ni cookie, ni autre schéma).
Les jetons se créent dans l'interface (Sécurité du compte) : le texte du jeton n'est montré qu'une fois.

Un jeton n'ouvre que les connexions cochées à sa création, jusqu'à son expiration (365 jours au plus, ou aucune si le propriétaire choisit « Jamais » : le jeton vaut alors jusqu'à sa révocation).
Un administrateur de l'installation peut voir les métadonnées (jamais le texte ni les connexions) des jetons de tous les comptes et les révoquer depuis la page « Administration » ; le jeton révoqué reçoit alors `401 invalid_token`, et son propriétaire n'est pas prévenu. Révoquer le jeton d'un autre administrateur exige une confirmation explicite (`DELETE /web/admin/tokens/{id}` avec `{"confirmed": true}`, sinon `409 confirmation_required` avec `reason: other_administrator_token`) et est tracé à part dans le journal d'administration.
Un jeton « toutes mes connexions » couvre celles du compte au moment de sa création ; le bouton « Actualiser » de
l'interface y ajoute ensuite les connexions créées depuis (et retire les supprimées) sans changer le texte du jeton,
donc sans rien reconfigurer côté client. Il est
supprimé quand le mot de passe du compte change. Par défaut un jeton ne fait que lire : une écriture sur `/query` est refusée par le filtre puis, en dernier recours, par le moteur
lui-même (transaction en lecture seule). Six droits se cochent à la création et ne changent plus (on émet un autre jeton) :
`writeData`, `writeSchema`, `createConnections` (voir plus bas), `diagnostic` (supervision du serveur, lecture seule, voir « Diagnostics »),
`readSchema` (liste des bases et des schémas) et `export` (voir « Lecture à cocher »). Tous sont refusés par défaut ; un jeton émis avant
l'arrivée d'un droit ne l'a pas (aucun élargissement implicite) et un jeton ne gère jamais d'autres jetons.

| Méthode | Chemin | Rôle |
| --- | --- | --- |
| GET | `/api/v1/token` | le jeton courant et son compte |
| GET | `/api/v1/connections` | les connexions permises : `id`, `name`, `engine` |
| GET | `/api/v1/connections/{id}/tables` | tables et vues, avec une estimation du nombre de lignes |
| GET | `/api/v1/connections/{id}/tables/{name}` | colonnes, index, clés étrangères, définition d'une vue ; sur Vitess, `streams` quand un flux Materialize / MoveTables lit la table |
| POST | `/api/v1/connections/{id}/query` | exécute UNE instruction de lecture : `{"sql": "…", "maxRows": 1000, "timeoutMs": 30000}` ; `timeoutMs` ne peut pas dépasser le délai du proxy moins 20 s (100 000 ms par défaut, `TERRILDB_PROXY_TIMEOUT` : voir docs/deploy.md), sinon 422 `invalid_input` ; un jeton SANS le droit `export` n'en reçoit que 500 lignes au plus (`truncated` si coupé, jamais une erreur), un jeton avec `export` garde le plafond du serveur |
| POST | `/api/v1/connections/{id}/lint` | conseils sur le TEXTE d'une lecture, (droit `readSchema` : les conseils citent des noms d'index de la cible ; sans lui 403 `permission_denied`) : `{"sql": "…"}` -> `{"findings": [{rule, severity, message, suggestion, columns}]}`, 50 au plus. Rien n'est exécuté : seule la structure de l'UNIQUE table d'une requête simple est lue (index, types), jamais pour une jointure, une sous-requête ou un CTE. Une condition non sargable n'est dite « l'index ne pourra pas servir » que si la colonne est en tête d'un index ordinaire (l'index est nommé) ; sans index en tête la règle se tait, sans structure lisible le conditionnel reste. Règles : `function_on_column`, `arithmetic_on_column`, `leading_wildcard_like`, `select_star`, `large_offset`, `not_in_subquery`, `row_lock`, `recursive_cte`, `type_mismatch`, `or_on_columns`, `distinct_join`, `count_star_large` (PostgreSQL, un million de lignes estimées), `random_order` (50 000 lignes estimées, sans WHERE). Un texte qui n'est pas UN SELECT / WITH … SELECT donne une liste vide. Audit `query.linted` : moteur, nombre et règles, longueur ; le texte (valeurs masquées par `?`, commentaires retirés, 200 caractères au plus) seulement pour une lecture jugée |
| POST | `/api/v1/connections/{id}/prepare` | (droit d'écriture) juge UNE instruction d'écriture SANS l'exécuter : `decision` (`allowed` / `needs_confirmation`), `kind`, `danger`, `reason` et un `confirmationToken` lié au texte exact, à la connexion et au jeton d'API, à usage unique, valable 300 s |
| POST | `/api/v1/connections/{id}/script/prepare` | (droit d'écriture) juge un SCRIPT (500 instructions / 1 Mo au plus) instruction par instruction avec les droits du jeton, sans rien exécuter : verdicts, `transactional`, `atomic`, `dryRun`, `warnings`, UN `confirmationToken` lié au texte exact ; une instruction refusée = 403 `script_refused` (`reason`, `statement`) et aucun jeton |
| POST | `/api/v1/connections/{id}/script/execute` | exécute le script préparé : `script`, `confirmationToken` (TOUJOURS obligatoire, même pour un jeton `singleStep`), `confirmed` (si destructif), `dryRun`, `timeoutMs` (global, 100 000 ms par défaut, au plus le délai du proxy moins 20 s : au-dessus, 422 `invalid_input` AVANT toute consommation du jeton ; voir docs/deploy.md). Arrêt à la première erreur ; réponse 200 avec `status` (`committed`, `dry_run`, `rolled_back`, `partial`, `failed`), `applied`, `failed`, `notRun`, `steps` ; audit : une ligne par instruction, `executionId` commun. Le jeton de confirmation est consommé par CHAQUE exécution, `dryRun` compris : tester puis exécuter pour de vrai demande deux `prepare`. Un `dryRun` est refusé (409 `dry_run_unavailable`, `reason: non_transactional_tables`) si le script écrit dans une table sans transaction (MyISAM, MEMORY…), et chaque lecture est limitée à 1000 lignes (`truncated`) |
| POST | `/api/v1/connections/{id}/script/run` | (droit d'écriture) lance le script préparé EN ARRIÈRE-PLAN : mêmes `script`, `confirmationToken` (TOUJOURS exigé, consommé), `confirmed`, `dryRun` que `script/execute`, `timeoutMs` (600 000 ms par défaut, 100 jusqu'au maximum de l'installation `TERRILDB_SCRIPT_RUN_MAX_SECONDS`, 4 h sauf réglage, sinon 422 `invalid_input` AVANT toute consommation du jeton). Réponse 202 `{run}` : `id`, `status` (`queued`, `running`, `committed`, `dry_run`, `rolled_back`, `partial`, `failed`, `cancelled`, `interrupted`), `finished`, `total`, `completed`, `statements` (rang, état, lignes touchées, durée, SQLSTATE ; jamais le texte). Worker absent ou plein : 503 / 429 comme sur le web |
| GET | `/api/v1/script-runs/{id}` | suit l'exécution (à scruter) ; SEUL le jeton qui l'a lancée la voit, tout autre reçoit 404 ; le propriétaire la voit sur le web |
| POST | `/api/v1/script-runs/{id}/cancel` | demande l'arrêt ENTRE deux instructions (celle qui tourne va à son terme, jamais de `KILL`) ; 409 si l'exécution est déjà terminée |
| POST | `/api/v1/connections/{id}/execute` | exécute UNE instruction d'écriture (droit `writeData` et/ou `writeSchema`) : mêmes champs que `/query` (sans le droit `export`, 500 lignes au plus aussi, `truncated` si coupé), plus `confirmationToken` (obligatoire, voir ci-dessous) et `confirmed` |
| GET | `/api/v1/connections/{id}/diagnostics` | (droit `diagnostic`) ce que chaque source de supervision peut lire : `capabilities.sessions`, `.locks`, `.statements`, `.variables`, `.replication` et `.logs` = `{availability, reason, privilege}` |
| GET | `/api/v1/connections/{id}/diagnostics/migrations` | (droit `diagnostic`, Vitess seulement, 501 ailleurs) les migrations de DDL en ligne : `uuid`, `table`, `status` (`queued`, `ready`, `running`, `complete`, `failed`, `cancelled`, `unknown`), `progress`, `statement` (littéraux masqués), `message` (masqué), et `actions` = les actions possibles avec leur instruction `ALTER VITESS_MIGRATION '<uuid>' …`, à exécuter par `prepare` puis `execute` (droit structure) |
| GET | `/api/v1/connections/{id}/diagnostics/tablets` | (droit `diagnostic`, Vitess seulement, 501 ailleurs) les tablets que vtgate sait viser : `alias`, `cell`, `keyspace`, `shard`, `type`, `serving`, puis `skipped` = ceux qu'on a écartés (`reason` : `type_not_targetable` / `unexpected_name` / `unreadable`, `type` seulement si Vitess le connaît : BACKUP, RESTORE, DRAINED…, jamais un alias ni une valeur du serveur) ; l'alias se passe en `?tablet=<alias>` à toute route de diagnostic ci-dessous (sans lui, un vtgate répond 501 ; alias mal formé ou inconnu : 422 `invalid_input`) |
| GET | `/api/v1/connections/{id}/diagnostics/sessions` | (droit `diagnostic`) les sessions en cours sur le serveur, lecture seule |
| GET | `/api/v1/connections/{id}/diagnostics/accounts` | (droit `diagnostic`) comptes, rôles, privilèges et constatations de l'audit de sécurité (MySQL, MariaDB), lecture seule, jamais une empreinte de mot de passe |
| GET | `/api/v1/connections/{id}/diagnostics/encryption` | (droit `diagnostic`) la connexion de TerrilDB vers la cible est-elle chiffrée (MySQL, MariaDB, PostgreSQL) |
| GET | `/api/v1/connections/{id}/diagnostics/locks` | (droit `diagnostic`) transactions ouvertes et arbre « qui bloque qui », lecture seule |
| GET | `/api/v1/connections/{id}/diagnostics/replication` | (droit `diagnostic`) état de la réplication du serveur : rôle, fils, retard, jeux reçu / appliqué, dernières erreurs, alertes ; lecture seule |
| GET | `/api/v1/connections/{id}/diagnostics/queries?order=&limit=` | (droit `diagnostic`) les requêtes les plus coûteuses par empreinte, lecture seule |
| GET | `/api/v1/connections/{id}/diagnostics/variables` | (droit `diagnostic`) variables de configuration, lecture seule : `q` (morceau de nom), `changed=1`, `limit` (1 à 500, 100 par défaut) |
| GET | `/api/v1/connections/{id}/databases` | (droit `readSchema`) les bases du serveur que le compte voit : `default`, `databases[]` `{name, system}` ; bases du moteur masquées sauf `?system=1` |
| GET | `/api/v1/connections/{id}/schemas` | (droit `readSchema`) les schémas d'une base (PostgreSQL ; 501 `unsupported_operation` ailleurs) : `?database=`, `?system=1` |
| POST | `/api/v1/connections/{id}/export` | (droit `export`) exporte le résultat d'UNE lecture : `sql`, `format` (`csv` ou `json`), `bom?`, `maxRows` (10 000 par défaut, 100 000 au plus), `timeoutMs` ; réponse = le fichier, en-tête `X-TerrilDB-Truncated: 1` si coupé |
| GET | `/api/v1/connections/{id}/diagnostics/config-audit` | (droit `diagnostic`) audit de la configuration : constats sur les variables lues, lecture seule, rien n'est exécuté |
| GET | `/api/v1/connections/{id}/diagnostics/logs` | (droit `diagnostic`) journal d'erreurs (MySQL) et requêtes lentes en table (MySQL, MariaDB), 200 lignes les plus récentes, textes masqués, lecture seule |
| GET | `/api/v1/connections/{id}/diagnostics/health?seconds=5` | (droit `diagnostic`) santé du serveur sur un intervalle de 1 à 30 s (5 par défaut) : la requête dure l'intervalle, lecture seule |
| POST | `/api/v1/connections` | enregistre une connexion (droit `createConnections`) : `name`, `engine`, `host`, `port?`, `database`, `username`, `password`, `transport?` (rebond SSH : `{type: "ssh", host, port?, username, privateKey, hostKeyFingerprint}` ; bastion déclaré par l'administrateur, empreinte à fournir, MySQL/MariaDB/PostgreSQL seulement), `readReplica?` (booléen, faux par défaut, MySQL seulement : Vitess, les lectures vont aux tablets réplica) |

Résultat d'une requête : `columns`, `rows`, `rowCount`, `truncated` (d'autres lignes existaient), `affectedRows`
(`null` pour une lecture), `durationMs`. Les entiers au-delà de 2^53 sont rendus en chaînes.

## Erreurs

Toujours `{"error": "<code>", …}`. Codes : `invalid_token` (401, en-tête `WWW-Authenticate: Bearer`), `not_found` (404,
connexion hors jeton comprise), `query_refused` (403, avec `reason`), `query_failed` (400, avec `message` du moteur),
`query_timeout` (408), `invalid_input` (422), `bad_request` (400), `too_many_attempts` (429, avec `Retry-After`),
`target_unreachable` (502), `permission_denied` (403, le jeton n'a pas le droit), `confirmation_required` (409, avec `reason`),
`deadlock`, `serialization_failure` et `lock_timeout` (409, `retryable`, jamais le message du moteur), `too_many_connections` (503), `engine_permission_denied` (403), `duplicate_key` (409), `auth_failed` et `auth_requires_tls` (502) (voir « Erreurs courantes du moteur »).

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

## Lecture à cocher

Sans droit, `403 permission_denied` (avant même de regarder la connexion). Chaque case est indépendante des autres et ne donne jamais d'écriture.
`readSchema` : listes de bases et de schémas seulement, aucune donnée de table ; audit `schema.read` (sujet, `topic`, moteur, nombre de lignes, jamais la
liste). `export` : mêmes garde-fous, lecture seule et audit que `/query` (`purpose: export`, `format`) mais jusqu'à 100 000 lignes : c'est une sortie
en masse, d'où une case à part : un jeton sans `export` est plafonné à 500 lignes par `/query` et par `/execute` (un SELECT lancé par un jeton qui écrit ; champ `truncated`), même si `maxRows` en demande davantage. Les jetons créés avant ce droit l'ont à faux (migration) : ils sont donc plafonnés à 500 lignes dès la mise à jour ; pour retrouver l'ancien plafond, en créer un nouveau avec `export`. Le contenu exporté vient de la base : une donnée, jamais une instruction. À venir (issue #37) : diagramme, audit du schéma et
plan d'exécution (`readSchema`), `editData`, `editStructure`, `manageConnections`, `readHistory`, validation humaine par jeton, limites de débit réglables.

## Erreurs courantes du moteur

**Réservé aux installations qui l'activent** : `TERRILDB_DETAILED_ERRORS=1` (faux par défaut, réglage d'installation, valable pour tous les membres). Sans lui,
TerrilDB garde ses réponses d'origine : un échec de connexion reste `target_unreachable`, une instruction refusée reste `query_failed` (400, `message`) ou
`data_change_failed` / `schema_change_failed`, et le journal garde `engine_error`. Raison : un serveur qui répond « identifiants refusés » ou « serveur plein »
prouve qu'il est joignable depuis TerrilDB, ce qui cartographie le réseau interne ; le choix revient à qui installe.

Avec le réglage, classées d'après le CODE du moteur, jamais son texte, et rendues sans le message du moteur (il peut citer une valeur, un hôte, un compte) ; le
corps porte `retryable` et le journal d'audit le code seulement (`reason`).

| Code | Statut | Cause | `retryable` |
| --- | --- | --- | --- |
| `too_many_connections` | 503 | serveur ou compte à sa limite de connexions (MySQL/MariaDB 1040, 1203, 1226 ; PostgreSQL 53300) | oui, `retryAfter` (5 s) et en-tête `Retry-After` |
| `engine_permission_denied` | 403 | le compte de la connexion n'a pas le droit sur la base cible (1044, 1142, 1143, 1227, 1370 ; 42501) : distinct du 403 `permission_denied`, qui concerne le jeton | non |
| `duplicate_key` | 409 | clé primaire ou index unique en doublon (1062 ; 23505) | non |
| `auth_failed` | 502 | identifiants de la cible refusés (1045 ; 28P01) | non |
| `auth_requires_tls` | 502 | le serveur exige une connexion chiffrée (MySQL/MariaDB 3159 ; PostgreSQL « no encryption » du `pg_hba.conf`) | non |

Les deux derniers (et `too_many_connections`) apparaissent à l'ouverture de la connexion, à la place de `target_unreachable`. Sur une modification de structure jouée
instruction par instruction, `retryable` est faux dès que `applied` n'est pas vide (ne pas rejouer telle quelle). Tout le reste garde
`query_failed` (400, avec `message`) ou `data_change_failed` / `schema_change_failed`.

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

**Réplication** (`/diagnostics/replication`) : `role` (`none` = « pas de réplication configurée », `source`, `replica`, `peer` = tables répliquées entre pairs, ClickHouse), `replicaCount` (null = le compte ne peut pas
les lister), `channels` (une entrée par canal : `receiver` et `applier` = `running`, `connecting` ou `stopped`, `lagSeconds` annoncé par le moteur, `received` et `applied`
= jeux de GTID ou positions WAL, `pendingApply`, `receiverError` et `applierError` = `{code, message}` au texte neutralisé : ni hôte, ni compte, ni mot de passe), `settings`
(`readOnly`, `superReadOnly`, `binlogFormat`, `gtid`), `alerts` (`code`, `severity` = `warning` ou `critical`, `channel`) et `thresholds` (30 s et 300 s, non réglables).
Le retard annoncé est trompeur : 0 peut cacher un receveur en retard, d'où `received` / `applied` / `pendingApply`. MySQL et MariaDB (syntaxe `REPLICA`, MySQL >= 8.0.22,
MariaDB >= 10.5.1, sinon `unsupported` / `version_not_supported`), PostgreSQL, ClickHouse et Redis. Aucune instruction de réplication n'est envoyée.
Droit à donner à la connexion : MySQL `REPLICATION CLIENT` (et `REPLICATION SLAVE` pour compter les réplicas), MariaDB `SLAVE MONITOR` (et `REPLICATION MASTER ADMIN`), PostgreSQL `pg_read_all_stats`,
ClickHouse `SELECT ON system.replicas`, Redis la commande `INFO`.
Sections propres au moteur, ajoutées à la réponse (une clé n'existe que si le moteur a la notion ; chacune porte son `status` : `available`, `permission_denied`, `disabled`
ou `unsupported`, et une liste vide avec `available` veut dire « rien », pas « illisible ») :
- `slots` (PostgreSQL, `pg_replication_slots`) : `{name, type, active, temporary, retainedBytes, walStatus, safeWalSize}`. `retainedBytes` = `pg_wal_lsn_diff(position courante, restart_lsn)`
  (position rejouée sur un réplica) ; un slot inactif qui retient du WAL remplit le disque du primaire.
- `replicas` (PostgreSQL `pg_stat_replication`, Redis lignes `slaveN`) : `{rank, state, connected, syncState, writeLagSeconds, flushLagSeconds, replayLagSeconds, behindBytes}` ;
  un rang, jamais d'adresse, de nom de machine ni de compte.
- `binlog` (MySQL / MariaDB) : `{enabled, fileCount, totalBytes, expireSeconds (0 = jamais), gtidExecuted, gtidPurged}` ; journal éteint = `disabled` / `binary_log_off`. `SHOW BINARY LOGS` n'est lu que
  journal allumé ; les GTID sont bornés à 500 caractères.
- `semiSync` (MySQL / MariaDB) : `{source, replica, clients}` avec `not_loaded` (greffon absent : `disabled` / `semi_sync_not_loaded`), `off`, `active`, `fallen_back` (activé mais repassé
  en asynchrone, côté source seulement).
- `replicatedTables` (ClickHouse, `system.replicas`, 200 au plus, les plus en retard d'abord) : `{database, table, readonly, sessionExpired, absoluteDelaySeconds, queueSize, insertsInQueue, lostParts}`
  et `truncated` ; aucun chemin Keeper ni message d'exception.
- `keyValue` (Redis, `INFO replication`) : `{linkStatus (up|down), lastIoSecondsAgo, syncInProgress, backlogSize, masterReplOffset}` ; `master_host`, `master_port` et le mot de passe de la source ne sont jamais renvoyés.
- `limits` : seuils des alertes d'octets et de file (voir plus bas), présent avec au moins une section.
Alertes ajoutées (seuils fixes, documentés) : `slot_inactive` (hors slot temporaire, même sans réplica), `slot_retains_wal` (>= 1 Gio avertit, >= 10 Gio critique), `slot_wal_unreserved` (avertit),
`slot_wal_lost` (critique), `replica_not_streaming`, `replica_lag_high` / `replica_lag_critical` (30 s / 300 s sur le pire des trois retards), `binlog_no_expiry` (journal allumé et expiration nulle),
`semisync_fallback`, `table_readonly`, `table_session_expired`, `table_lag_high` / `table_lag_critical` (30 s / 300 s), `table_queue_high` (file >= 100), `table_lost_parts`, `link_idle` (Redis, source silencieuse
depuis >= 30 s, critique >= 300 s) ; `channel` désigne le slot, le rang du réplica ou la table `base.table`. Une section illisible ne produit aucune alerte.

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

**Audit de configuration** (`/diagnostics/config-audit`, outil MCP `audit_config`) : `ConfigAuditor` applique des règles PURES aux variables déjà lisibles
(lues par nom exact, pas toute la liste). Réponse : `status`, `engine`, `evaluated` / `rulesTotal` (combien de règles avaient leurs variables ; « aucun
constat » avec peu de règles évaluées ne prouve rien) et `findings` : `rule` (code stable), `severity` (`high` / `medium` / `low`), `variable`, `value`
(ce que le serveur annonce), `recommended`, `suggestion` (une commande à LIRE, jamais exécutée par TerrilDB ; nulle quand la variable ne se change
qu'au redémarrage, dans le fichier de configuration), `explanation` (texte français fixe). Une variable masquée, absente ou illisible n'est pas jugée.
Règles MySQL / MariaDB : `sql_mode_not_strict`, `local_infile_enabled`, `skip_name_resolve_missing`, `binlog_never_expires` (journal binaire actif, ni
`binlog_expire_logs_seconds` ni `expire_logs_days` > 0), `flush_log_at_commit_relaxed`, `sync_binlog_relaxed` (journal actif seulement),
`performance_schema_off`, `slow_query_log_off`, `general_log_on`, `max_allowed_packet_tiny` (< 1 Mio), `tmp_table_above_heap_table` (MariaDB seulement),
`secure_file_priv_empty` (moyen sur MySQL, faible sur MariaDB où c'est le défaut), `doublewrite_off`. PostgreSQL : `fsync_off`, `autovacuum_off`,
`zero_damaged_pages_on`, `ignore_checksum_failure_on` (graves), `full_page_writes_off`, `ssl_off`, `ssl_min_protocol_old` (seulement si `ssl` est actif),
`track_counts_off` (moyens), `synchronous_commit_off`, `slow_statement_log_off`, `data_checksums_off` (faibles). Écartées volontairement : taille du cache
(`innodb_buffer_pool_size`, `shared_buffers`) et plafond de connexions, qui demandent la RAM et les compteurs d'état (pas lus ici) ; `password_encryption`
(son nom est masqué, donc illisible). ClickHouse : `unsupported` / `no_rules` ; Redis : 501. Un compte sans droit particulier lit les mêmes variables
(MySQL, MariaDB, PostgreSQL ; PostgreSQL laisse vides celles que seul `pg_read_all_settings` lit : la règle concernée se tait) ; ClickHouse sans
`SELECT ON system.server_settings` = `permission_denied`. Audit `diagnostic.read` : `topic: config_audit`, `status`, `rows` (règles évaluées),
`findings`, jamais un nom de variable ni une valeur.

### Journaux du serveur (`/diagnostics/logs`, outil MCP `list_logs`)

Lecture EN SQL seulement, jamais d'un fichier de l'hôte. Réponse : `errorLog` et `slowQueries`, chacun avec son `status` (`availability` : `available`,
`unsupported`, `permission_denied`, `disabled`, plus `reason` et `privilege`) : une source non lisible n'est jamais une liste vide. `truncated` est vrai si le
serveur a plus que les 200 lignes les plus récentes rendues.

- **Journal d'erreurs** (`errorLog.entries` : `loggedAt`, `level` = `system|error|warning|note`, `subsystem`, `code`, `message`) : MySQL 8.0.22 et plus
  (`performance_schema.error_log`, droit SELECT ; `performance_schema` éteint = `disabled` / `performance_schema_off`). MariaDB et PostgreSQL : `unsupported`
  / `log_is_a_file` (leur journal est un fichier du serveur ; en lire un exigerait un accès aux fichiers de l'hôte, que TerrilDB ne fait pas). Le `message` est
  masqué (chaînes entre guillemets, adresses IP, `utilisateur@hôte`, valeur d'un mot de passe ou jeton) puis raccourci à 300 caractères ; il peut malgré
  tout nommer des objets.
- **Requêtes lentes** (`slowQueries.queries` : `startedAt`, `user`, `database`, `durationMs`, `lockMs`, `rowsSent`, `rowsExamined`, `query`, `queryReason`,
  plus `thresholdSeconds` = `long_query_time`) : MySQL et MariaDB, `mysql.slow_log`, seulement si `log_output` contient `TABLE` (`disabled` /
  `log_output_without_table`) ET `slow_query_log` est allumé (`disabled` / `slow_query_log_off`) ; SELECT refusé = `permission_denied`
  (`SELECT ON mysql.slow_log`). `query` a ses littéraux remplacés par `?` (nul + `queryReason: redacted` si illisible) ; l'hôte du client n'est jamais rendu.
  TerrilDB n'active rien : pour allumer ce journal, lancer soi-même `SET GLOBAL log_output = 'TABLE'; SET GLOBAL slow_query_log = 'ON';` (et l'écrire dans la
  configuration pour qu'il survive à un redémarrage).

Réservé au droit de jeton `diagnostic` (refusé par défaut). ClickHouse et Redis : 501 `unsupported_operation`. L'audit (`diagnostic.read`, `topic: logs`) garde
l'état et le nombre de lignes de chaque source, jamais une ligne ni un texte.

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
10 s pour une session inactive) : sur un intervalle très court, une activité récente peut ne pas encore y figurer. Les taux PostgreSQL sont donc des ordres de grandeur (mesuré : de 1 à 2 fois
la charge réelle sur 3 à 5 s, un rattrapage du paquet précédent sur 2 s, rien pour une session qui ne touche aucune table) : à lire sur 10 s ou plus ; MySQL et MariaDB comptent exactement. ClickHouse et Redis : `unsupported`, route 501. À
suivre (non livré) : longueur de l'historique d'annulation InnoDB, journal général, préparation à la reprise (les journaux d'erreurs et de requêtes lentes sont livrés : voir « Journaux du serveur »). L'audit
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

Requêtes coûteuses : MySQL/MariaDB `SELECT` sur `performance_schema` ; PostgreSQL `pg_monitor` ou `pg_read_all_stats` (sans lui le serveur masque le texte des
requêtes des autres) ; ClickHouse `SELECT ON system.query_log` ; Redis, sans objet.
Variables : MySQL et MariaDB, aucun droit particulier (`SELECT` sur `performance_schema` implicite pour MySQL) ; PostgreSQL, lisible par tout compte, mais les
valeurs réservées (chemins, commandes) ne sont lues qu'avec `pg_read_all_settings` (inclus dans `pg_monitor`) ; ClickHouse `SELECT ON system.server_settings`.

Verrous et transactions : MySQL `PROCESS` + `SELECT` sur `performance_schema` + `SELECT` et `EXECUTE` sur `sys` (verrous de métadonnées) ; MariaDB `PROCESS` ;
PostgreSQL `pg_monitor` ; ClickHouse et Redis, sans objet.

**Comptes et privilèges** (`/diagnostics/accounts`, MySQL et MariaDB ; les autres moteurs répondent 501) : `accounts` (`user`, `host`, `name`, `plugin`,
`passwordSet` = le compte a un mot de passe, **jamais l'empreinte**, `passwordExpired`, `locked`, `tls` = `none | any | x509 | specified`, `isRole`, `current` = le
compte que TerrilDB utilise, `roles` accordés, `defaultRoles` activés à la connexion, `globalPrivileges`, `databasePrivileges` par base, `grantOptionOn`, `limits` non nulles)
et `findings` (`rule`, `severity` = `high | medium | low`, `account`, `detail`, `currentAccount`). Règles : `anonymous_account`, `wildcard_host`,
`same_name_many_hosts`, `no_password`, `legacy_plugin`, `disabled_plugin`, `password_expired`, `dangerous_privilege` (`FILE`, `PROCESS`, `SUPER`, `SHUTDOWN`,
`CREATE USER`, « via » le rôle qui les donne), `all_privileges`, `grant_option`, `role_never_active`, `no_tls_requirement`, `open_test_database` ; le compte de la
connexion qui porte un droit dangereux est en gravité haute (le compte de la base cible est le dernier rempart de la lecture seule). Comptes verrouillés et rôles
n'ont pas de constatation propre. Compte de la connexion sans droit de lire le catalogue : `permission_denied` avec `privilege: SELECT ON mysql` (droit `SELECT` sur
`mysql.*` ; MySQL : `user`, `role_edges`, `default_roles` ; MariaDB : `global_priv`, `roles_mapping`). 300 comptes au plus (`truncated`) et 20 000 lignes de droits par source ; `privilegesIncomplete` dit que des droits ont pu être manqués (liste coupée, ou deux comptes dont le nom s'écrit pareil dans `information_schema` : aucun droit ne leur est attribué) ; l'audit ajoute alors la constatation `incomplete_check` (gravité moyenne, `detail` = `accounts` ou `privileges`) au lieu d'un silence.
**Chiffrement** (`/diagnostics/encryption`) : `encrypted`, `protocol`, `cipher` (MySQL/MariaDB `Ssl_version`/`Ssl_cipher` de la session, PostgreSQL `pg_stat_ssl`) ;
une observation, aucun droit requis, les paramètres de connexion ne changent pas ; ClickHouse et Redis : 501.

## Limites

120 requêtes par minute et par jeton ; 30 échecs d'authentification par quart d'heure et par adresse.

## Exemple

```bash
curl -s -H "Authorization: Bearer $TERRILDB_TOKEN" -H 'Content-Type: application/json' \
  -d '{"sql": "select id, name from categories order by id", "maxRows": 10}' \
  http://localhost:8000/api/v1/connections/$CONNECTION_ID/query
```
