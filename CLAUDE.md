# CLAUDE.md — TerrilDB

Client / gestionnaire de bases de données web, auto-hébergé : entre phpMyAdmin et la console BDD de
PhpStorm. TerrilDB **n'est pas** un moteur : il se connecte à des bases existantes.
Trois entrées partageant le même cœur : **interface web**, **API REST**, **serveur MCP**.

## Règles non négociables

1. **TDD only** : test rouge d'abord, puis le code minimum pour le vert, puis refactor. Aucun code de
   production sans test qui l'a précédé. Un commit = un lot atomique (test + code).
2. **Dépôt GitHub public** (compte `gh`) depuis le 3 novembre 2026 : tout ce qui est poussé est lisible par tous.
   Aucune référence à un projet ou une organisation
   tiers dans le code, les docs, les tests ou l'historique.
3. **Dépôt public** : aucune donnée personnelle visible (e-mail, nom réel, chemins
   absolus de la machine, secrets), ni dans les fichiers ni dans l'historique. Identité git = compte GitHub
   configuré avec `gh` (adresse `…@users.noreply.github.com`). Pour GitHub, utiliser `gh`.
4. **Zéro information de connexion dans le code** (hôte, base, identifiant, même placeholder).
5. **Secrets zero-knowledge** : la clé de chiffrement des identifiants de connexion est dérivée du mot de
   passe de l'utilisateur (libsodium) ; le serveur seul ne peut pas déchiffrer.
6. Dernières versions stables de langage et framework.
7. Un choix d'architecture non couvert ici : poser la question, ne pas deviner.
8. **Commits sans signature d'IA** : jamais de ligne `Co-Authored-By: Claude …` ni de mention « Generated with Claude
   Code » dans un commit ou une description de PR (consigne du propriétaire, elle prime sur l'attribution par défaut).
   Messages en français, style existant (`feat(…)`, `docs: …`). Identité git du dépôt : compte GitHub (`.git/config`
   local, adresse `noreply`), rien de global.

## Stack

- PHP 8.5, Symfony 8.1, Doctrine DBAL pour les bases **cibles** (jamais l'ORM) ; Doctrine ORM seulement
  pour la base **interne** (PostgreSQL : utilisateurs, connexions chiffrées, historique, tokens, audit).
- Front : Vue 3 + TypeScript + Vite, CodeMirror 6, Pinia ; thème sombre, design tokens en variables CSS.
- MCP : SDK officiel `mcp/sdk` (+ `symfony/mcp-bundle` si adapté), confiné à l'adaptateur `Http/Mcp`.
- Qualité : PHPStan niveau max, PHP-CS-Fixer, PHPUnit, ESLint + Vitest.

## Architecture

```
src/
  Core/        cœur métier sans dépendance web : Query, Schema, Export, Guard, Optimizer
  Driver/      un driver + un introspecteur par moteur (MySQL/MariaDB, PostgreSQL, ClickHouse, Redis…)
  Entity/ Repository/   base interne
  Connection/  gestion des connexions de l'utilisateur (paramètres chiffrés avec sa clé)
  Crypto/      libsodium, dérivation de clé depuis le mot de passe
  Security/    mot de passe Argon2id, TOTP, invitations, rôles
  Http/Web/ Http/Api/ Http/Mcp/   adaptateurs fins autour du cœur
assets/        SPA Vue
docker/        images + bases cibles de test
tests/
```

Les contrôleurs, l'API et le MCP ne contiennent **aucune** logique métier : ils appellent `Core`.
Toute spécificité moteur passe par une interface de driver (SQLite / SQL Server ajoutables sans refonte).
Lecture seule MCP/API par défaut : filtre lexical **et** transaction read-only côté moteur (le moteur fait foi). Un jeton peut recevoir des droits d'écriture (voir « API REST à jetons ») : le filtre les borne alors seul, sans filet du moteur pour la distinction données/structure.

## Périmètre

MVP (brief) d'abord, puis par lots : multi-utilisateur + invitations → optimiseur local (plan
d'exécution + règles d'index ; sans PMM/Vitess) → audit d'optimisation → ER diagram → Redis → ClickHouse.
Hors lots : diff de schémas, import CSV, assistant IA intégré. (La grille de données éditable et l'éditeur de structure sont livrés : voir « Édition de structure » et « Édition de données ».)

## Licence et contributions (LICENSE, CONTRIBUTING.md)

Licence **AGPL-3.0-only**, un seul titulaire des droits : l'objectif est de garder la liberté de faire évoluer la licence
(offre commerciale, double licence, cession) ; ne rien faire qui l'entame. Donc **aucune pull request de code extérieure
n'est acceptée** tant qu'un accord de contribution (CLA) n'existe pas (`CONTRIBUTING.md` : issues bienvenues, pas de PR de
code ; les mises à jour Dependabot sont exclues) ; ne jamais fusionner de code tiers sans cet accord ni copier du code sous
une autre licence. Dépendances : uniquement permissives (MIT, BSD, Apache-2.0, ISC…) ; aujourd'hui seules exceptions
acceptées, `lightningcss` (MPL-2.0, outil de compilation, non modifié) et les polices @fontsource (OFL-1.1, livrées avec leur
licence). Toute nouvelle dépendance GPL/AGPL/SSPL ou sans licence claire : poser la question. Les bases cibles (MySQL,
MariaDB, Redis…) ne sont que des services joints par le réseau, jamais embarqués. Signalement de failles : option GitHub
« Report a vulnerability » (activée). L'historique git a été réécrit le 9 novembre 2026 (retrait des signatures d'IA, poussé
de force) : tout clone antérieur est à recloner. Aucune référence à un employeur, un client ou une organisation tiers dans
le dépôt, y compris pour ces sujets.

## Crypto (src/Crypto)

Zero-knowledge, sans dépendance web/Doctrine. Flux : mot de passe → `KeyDerivation` (Argon2id, paramètres
`KdfParameters` versionnés et stockés par utilisateur) → `DerivedKey` (en mémoire seulement, effaçable,
non sérialisable) → `SecretCipher` (XChaCha20-Poly1305, format `v1:`…, l'identifiant de la ligne sert de
contexte authentifié). `SessionKeyVault` garde la clé le temps de la session (implémentation en mémoire
pour l'instant ; le stockage réel est à trancher). `SecretRotator` rechiffre tout-ou-rien au changement de
mot de passe. Toutes les erreurs héritent de `CryptoException`. Le hash d'authentification du mot de passe
est distinct (sel propre) et vit dans `Security/`, pas ici.

### Phrase de récupération (facultative, `RecoveryPhrase`, `RecoveryPhraseService`)

160 bits aléatoires en base 32 de Crockford (8 groupes de 4, lecture tolérante : casse, espaces, tirets, O/I/L). Le serveur
ne garde que `User::recoveryKey` : la clé de chiffrement enveloppée par un secret tiré de la phrase (`SessionKeyWrapper`,
contexte `recovery:<id>`), jamais la phrase. Elle sert à un seul geste, choisir un nouveau mot de passe sans connaître l'ancien
(`PasswordChanger::resetWithKey` + `SecretRotator::rotateWithKey`, même rotation tout-ou-rien du TOTP et des connexions,
jetons d'API révoqués). Elle est supprimée dès que la clé change (réinitialisation OU changement de mot de passe ordinaire,
`User::changePassword`) : il faut alors en générer une nouvelle. Création et retrait exigent session ET mot de passe ;
`POST /web/recover` (public, plafonné comme la connexion, réponse unique `invalid_recovery` pour phrase fausse, compte sans
phrase ou compte inconnu) n'ouvre AUCUNE session : le second facteur reste exigé à la connexion suivante. Une phrase
perdue = pas de récupération (comportement par défaut inchangé tant qu'on n'en a pas généré).

## Connexions (src/Connection, src/Core/Connection)

Une `DatabaseConnection` garde en clair son nom et son moteur (`Engine`) ; hôte, port, base, identifiant et mot
de passe forment un `ConnectionParameters` (JSON versionné) chiffré d'un bloc avec la clé de l'utilisateur,
contexte authentifié `connection:<uuid>`. L'entité refuse tout ce qui n'a pas la forme d'un chiffré. Les lectures
sont toujours cloisonnées par propriétaire (`findOwned`), une connexion d'autrui est indiscernable d'une
connexion inexistante. `TargetHostPolicy` (+ `HostResolver`) : seul un administrateur peut viser le serveur lui-même (boucle locale
127.x/::1, lien local 169.254.x/fe80::, 0.0.0.0, `localhost`, et la base interne repérée par l'hôte de `DATABASE_URL`, par nom OU par
adresse) ; un membre garde les autres machines, réseaux privés compris. Contrôle à la création, à la modification, à l'essai et à
l'OUVERTURE (`TargetOpener`, donc aussi pour une connexion enregistrée avant la règle), pour l'interface comme pour les jetons ;
erreur 403 `forbidden_target` sans jamais dire ce que le nom a donné ; tout refus est journalisé (`AuditedTargetHosts`, action `target.forbidden`, adresse saisie et port seulement, porte et jeton attribués). Le nom n'est résolu qu'UNE fois (`TargetHostPolicy::pinnedAddress`, IPv4 d'abord) et le driver reçoit
l'adresse contrôlée (`ConnectionParameters::withAddress`, jamais dans le JSON chiffré ; `AuditedTargetHosts::pinned`) : une réponse DNS qui change
ensuite n'a plus d'effet. L'hôte reste le nom pour l'identité : en-tête Host et TLS ClickHouse (option `resolve` du client HTTP), `peer_name` Redis TLS ;
PDO MySQL/PostgreSQL se connectent à l'adresse (aucune vérification de nom TLS n'y est configurée). Un nom à plusieurs adresses n'a pas de repli
sur la suivante (la première est épinglée). `PasswordChanger` rechiffre TOTP et connexions ensemble, tout-ou-rien : une connexion
illisible bloque le changement.
Ordre et couleur (en clair, rien de secret) : colonnes `position` (rang dans la liste du propriétaire ; une connexion créée va à la fin, `ConnectionManager::reorder` met les identifiants cités en tête et garde les autres derrière, un identifiant inconnu, répété ou d'autrui = 422 `invalid_input` sans dire lequel) et `color` (`ConnectionColor`, huit noms, jamais une valeur CSS libre ; jetons `--conn-*` clairs et sombres). `PUT /web/connections/order` `{ids}` ; `color` dans la création et la modification (absente = aucune). Front : glisser-déposer ou Alt+flèche dans l'arbre (`connections.reorder|move|moveOnto`, affichage immédiat puis rechargement si le serveur refuse).

## Introspection (src/Core/Driver, src/Core/Schema, src/Driver)

Un `Driver` (tagué `app.driver`, rassemblés dans `DriverRegistry`) sert un ou plusieurs `Engine` : il ouvre une
`TargetConnection` (DBAL + `SchemaIntrospector`). `DbalConnector` borne le délai de connexion et vérifie par un
`SELECT 1` ; un échec rend toujours `TargetConnectionFailedException` au message générique (le détail du moteur
reste dans l'exception précédente, jamais montré à l'utilisateur). Drivers : `MySql` (MySQL + MariaDB, information_schema),
`PostgreSql` (pg_catalog), `ClickHouse` et `Redis` (voir plus bas). `TargetConnection` ne dépend d'aucune bibliothèque
d'accès : le driver lui donne l'exécuteur, la façon de se libérer, et selon le moteur un introspecteur (SQL) et/ou une
navigation par clés (`keys()`). Ce qui manque lève `UnsupportedOperationException` (501 `unsupported_operation`). Les introspecteurs rendent le modèle `Core/Schema` (types natifs du moteur, index
primaire d'abord puis par nom, tri à l'octet car l'ordre du moteur dépend de la collation) ; périmètre = base de la
connexion (MySQL) ou schéma courant (PostgreSQL). Nombres de lignes = estimations du moteur, jamais un COUNT. Les noms
de tables passent toujours en paramètres liés. `TargetOpener` relie le tout : connexion enregistrée + clé -> cible ouverte.
Bases du serveur : `SchemaIntrospector::listDatabases()` (MySQL/MariaDB `information_schema.SCHEMATA` = seulement celles où le compte a un droit ; PostgreSQL `pg_database` sans modèles et avec `CONNECT` ; ClickHouse `system.databases` ; tri à l'octet ; `DatabaseSummary::system` pour information_schema/mysql/performance_schema/sys/system, masquées sauf `?system=1`). `GET /web/connections/{id}/databases` ; une autre base s'explore en OUVRANT la connexion sur elle (`TargetOpener::open(…, $database)` -> `ConnectionParameters::withDatabase`, adresse épinglée conservée) : une connexion reste une base, y compris sur PostgreSQL. Routes tables, structure, diagramme, audit et console acceptent `?database=` (corps `database` pour la console). Une base inconnue = échec de connexion générique (502). Redis : pas de liste (501). Tables paginées : `SchemaIntrospector::listTablesPage(TablePageRequest)` -> `TablePage` (`nextCursor` = nom de la dernière table, `total` seulement sur la première page, lu dans le catalogue). Pagination PAR CLÉ côté moteur, jamais en tranchant une liste complète : MySQL/MariaDB `ORDER BY BINARY TABLE_NAME` + `BINARY TABLE_NAME > BINARY ?`, PostgreSQL `COLLATE "C"`, ClickHouse `name > {after}` ; limite 1 à 500 (100 par défaut) ; recherche `q` = sous-chaîne sans casse en paramètre lié (`INSTR`/`strpos`/`positionCaseInsensitive`, donc `_` et `%` littéraux). `GET /web/connections/{id}/tables?limit=&cursor=&q=&database=` : sans `limit`/`cursor`/`q` la liste COMPLÈTE reste servie (compatibilité). Front : `workspace.tables` = tables chargées (cent par cent, `loadMoreTables`, `searchTables` côté serveur, réponse périmée ignorée) ; l'autocomplétion de l'éditeur SQL ne connaît donc que les tables chargées. Front : base et schéma se choisissent dans l'arbre des connexions (plus de sélecteur dans l'éditeur ; `?database=` / `?schema=` de l'adresse pilotent `workspace.database` / `workspace.schema`, et `WorkspaceView` ouvre la première base d'une connexion qui n'en nomme aucune). L'autocomplétion de l'éditeur connaît TOUTES les tables : `workspace.loadCompletion()` lit la liste complète des noms (route sans pagination), une fois par connexion/base/schéma.
La base d'une connexion est FACULTATIVE (`database` vide) : sans elle on joint le serveur (PostgreSQL ouvre la base `postgres`, ClickHouse `default`, Redis le numéro 0), `GET .../databases` rend `default` (null si aucune) et l'interface ouvre alors la première base de la liste. Schémas PostgreSQL : `SchemaIntrospector::listSchemas()` (501 ailleurs ; pg_catalog/information_schema masqués sauf `?system=1`, TOAST et temporaires jamais), `GET .../schemas?database=`, paramètre `?schema=` (corps `schema` pour la console) sur les mêmes routes : le schéma s'applique à l'OUVERTURE par `search_path` (`ConnectionParameters::withSchema`, éphémère comme l'adresse épinglée, jamais dans le JSON chiffré ; `set_config` + `quote_ident`, le nom est une donnée). Front : niveau schéma dans l'arbre, `workspace.schema`.

## Exécution et garde-fous (src/Core/Guard, src/Core/Query, src/Driver/*/…QueryRunner)

`QueryService` est le point d'entrée unique (web, API, MCP) : `StatementGuard` examine, `QueryRunner` du moteur exécute.
Le garde (lexical, `SqlScanner` par dialecte) n'accepte qu'UNE instruction, refuse les constructs hors transaction
(INTO OUTFILE, FOR UPDATE, fonctions de fichiers, LOAD/COPY, SET/BEGIN/COMMIT…) et demande confirmation pour ce qui
détruit (UPDATE/DELETE sans WHERE de même niveau, DROP, TRUNCATE, ALTER, GRANT, CALL). Un refus est définitif, aucune
confirmation ne le lève. Un identifiant entre guillemets ou backticks suivi de `(` (commentaires et espaces permis entre les deux, nom qualifié compris) est un APPEL : son nom déquoté, en majuscules, est comparé aux listes de fonctions refusées (`ScannedStatement::calledNames()`), sans devenir un mot du garde (une colonne `"sleep"` reste lisible). `QueryMode::ReadOnly` par défaut ; l'API et le MCP ne passent en lecture-écriture que par `ApiTokenAccess::writeOptions` (jeton avec droit d'écriture, `QueryOptions::$writes` = `WritePermissions` données/structure, refus définitif `write_not_permitted` du garde, avant toute confirmation ; CALL/DO exigent le droit structure). Le garde juge CHAQUE niveau : un WITH prend la nature de son instruction de tête (`ScannedStatement::withHead`, WITH … DROP = structure), une expression de table qui écrit (PostgreSQL `WITH d AS (DELETE …) SELECT …`) est une écriture, et un UPDATE/DELETE sans WHERE DANS SA PROPRE paire de parenthèses (`hasUnboundedRowChange`, y compris MERGE … THEN DELETE) demande confirmation ; `replace(…)`/`insert(…)` appelés ne sont pas des verbes (`isCallAt`), `FOR UPDATE`, `ON DUPLICATE KEY UPDATE`, `DO UPDATE` non plus. Refus définitif, dans les DEUX modes : fichiers/contrôle du serveur (`DANGEROUS_FUNCTIONS`) et verrous durables (`GET_LOCK`, `pg_advisory_*` : le moteur ne les refuse pas en lecture seule, le filtre est seul) ; `nextval`/`setval` = écriture (même derrière WITH ou EXPLAIN ANALYZE) ; pauses (`SLEEP`, `pg_sleep`, `BENCHMARK`) permises, coupées par le délai du moteur. OPTIMIZE/ANALYZE/VACUUM/REINDEX/CLUSTER et `CREATE OR REPLACE` demandent confirmation. Ce que le moteur EXÉCUTE se juge, pas l'enveloppe (`ScannedStatement::executedVerb`) : `EXPLAIN ANALYZE <instruction>` / `EXPLAIN (ANALYZE …)` = l'instruction expliquée (CREATE = structure, droit `writeSchema`, DELETE = données…), `ANALYZE <écriture>` (MariaDB) idem, `ANALYZE TABLE` reste une maintenance, un EXPLAIN ANALYZE dont l'instruction n'est pas un verbe connu (EXECUTE, DECLARE…) est refusé (`unsupported_statement`) ; PostgreSQL `SELECT … INTO <table>` (aussi derrière WITH ou EXPLAIN ANALYZE) CRÉE une table : structure + confirmation en lecture-écriture, `forbidden_construct` en lecture seule. `WHERE 1 = 1` et les tautologies ne sont PAS détectées (impossible en lexical : le moteur et les droits du compte restent les remparts). Corpus de référence : `tests/Core/Guard/StatementGuardCorpusTest` (une ligne = instruction, moteur, mode, verdict) et `tests/Integration/Driver/GuardEngineConcordanceTest`. Le filtre peut se
tromper : le moteur fait foi. PostgreSQL : `BEGIN READ ONLY` + `statement_timeout` local + curseur serveur (limite de
lignes). MySQL/MariaDB : `SET SESSION TRANSACTION READ ONLY` (et non `START TRANSACTION READ ONLY`, qui laisse passer
le DDL), `MAX_EXECUTION_TIME` / `max_statement_time`, lecture non tamponnée, instructions multiples désactivées. Une
lecture est toujours annulée ; une écriture est validée si tout réussit. Limites connues : MySQL n'a pas de délai moteur
pour une écriture ; un DDL MySQL en lecture-écriture valide implicitement ; l'opérateur `?` de jsonb ne passe pas dans
EXPLAIN/SHOW sur PostgreSQL (il passe dans les SELECT).

Verrous : `LockConflict` (Core/Driver, `fromEngineError(Engine, sqlState, code)`, codes du moteur seulement : MySQL/MariaDB 1213 = `deadlock`, 1205 = `lock_timeout` ; PostgreSQL 40P01 = `deadlock`, 40001 = `serialization_failure` (réessayable, code propre : ce n'est pas un interblocage), 55P03 = `lock_timeout` ; 57014 et 3024 restent `query_timeout`) ; les runners lèvent `LockConflictException`, `DbalDataExecutor` / `DbalDdlExecutor` posent `lock` sur `DataChangeFailedException` / `SchemaChangeFailedException` (`EngineLockConflict` lit PDO). `ErrorMapper` : 409 `deadlock` / `serialization_failure` / `lock_timeout` avec `retryable` (vrai SEULEMENT si `applied` est vide : un DDL MySQL/MariaDB/ClickHouse joué instruction par instruction qui a déjà appliqué quelque chose répond `retryable: false`, et le front dit de ne pas rejouer), sans message du moteur (il peut citer une valeur), `applied` et `failed` conservés ; `data_change_failed` / `schema_change_failed` portent `retryable: false` ; TerrilDB ne rejoue jamais seul ; journal `query.failed` avec `reason` = le code. Tests : `tests/Integration/Driver/LockConflictTest` (vrais verrous et vrai interblocage, pair en sous-processus `lock_peer.php`). Restent (#73) : conseils pour 1040/53300/1044/1142/1062 et `auth_requires_tls`.

## Couche web (src/Http/Web)

API JSON de la SPA, routes `/web/*` (l'API REST à jetons sera `/api/v1/*`). Contrôleurs minces : `AccountController`
(inscription, connexion, `/web/me`), `AccountSecurityController` (mot de passe, TOTP, codes de récupération),
`ConnectionController`, `SchemaController`, `QueryController`. La connexion n'est pas faite par un authentificateur
Symfony mais par `LoginService` (qui dérive la clé) ; `WebSession::open` démarre la session, renouvelle son identifiant,
ouvre la session Symfony (`ProgrammaticAuthenticator`) et confie la clé au coffre : toujours démarrer la session AVANT de
lire son identifiant. Une session perd sa reconnaissance quand le hash du mot de passe change (`SecurityUser::isEqualTo`).
Protections : en-tête `X-TerrilDB-Request: 1` obligatoire sur toute requête non sûre (CSRF), plafond de tentatives
(`LoginThrottle` : 30/15 min par adresse ; 8/15 min par compte seulement sur les actions qui vérifient le mot de passe d'une session ouverte ; connexion et récupération par phrase passent par `LoginLockout`, blocage À PALIERS du COUPLE adresse + compte : 5 échecs de suite -> 15 min, puis 1 h, 3 h, 6 h, 12 h à chaque nouveau blocage (palier oublié après un jour calme, réussite = échecs remis à zéro), le compte n'est jamais verrouillé pour tous ; une adresse dont les blocages touchent 3 comptes différents est bannie 24 h, puis 3 jours, puis 7 jours ; compte inconnu = compte connu ; état dans le cache `cache.rate_limiter`, blocage ou ban déclenché = une ligne d'audit `login.throttled` avec `kind` et `seconds` ; levée manuelle d'un ban : `app:ban:lift <ip>`, le palier est conservé),
erreurs du domaine traduites en codes stables par `ErrorMapper` (jamais le message du moteur d'une cible injoignable).
Inscription : `APP_REGISTRATION=first_only` (défaut, seulement le premier compte) ou `open`. Le premier compte peut aussi naître en console : `app:user:create-first <e-mail> <utilisateur>` (`CreateFirstAccountCommand`, mot de passe saisi deux fois sans écho, jamais en argument ; refusé sans terminal et dès qu'un compte existe).
Tests fonctionnels : `tests/Integration/Web/WebApiTestCase` (conteneur redémarré à chaque requête, écritures annulées par DAMA).

## API REST à jetons (src/ApiToken, src/Http/Api, docs/api.md)

Le serveur ne détient jamais la clé de l'utilisateur ; un jeton d'API (`tdb_<id>_<secret>`) emporte donc une copie de
la clé enveloppée par son propre secret (`SessionKeyWrapper`, contexte = id du jeton). Le serveur ne garde que
l'empreinte du secret (`ApiTokenSecret::verifier`) et l'enveloppe : sans le jeton, rien n'est lisible. Lecture seule
par défaut, connexions listées (au moins une, sauf jeton « toutes » ou autorisé à en créer), expiration de 365 jours au plus OU aucune (`neverExpires: true`, `expires_at` nul : choix du propriétaire, la révocation reste possible à tout moment), supprimé à tout changement
de mot de passe (`PasswordChanger` → `ApiTokenService::revokeAll`, même transaction). Pare-feu `api` sans session
(`BearerTokenAuthenticator`) ; échecs plafonnés par adresse, requêtes plafonnées par jeton (`ApiThrottle`). Les
assistants JSON partagés avec l'interface web sont dans `App\Http\Json`.
Droits d'un jeton (`TokenPermissions`, fixés à la création, tous refusés par défaut) : `diagnostic` (supervision, voir « Diagnostics du serveur »), `writeData` (INSERT/UPDATE/DELETE),
`writeSchema` (CREATE/ALTER/DROP/TRUNCATE/GRANT/CALL), `createConnections`. Écrire passe par `POST /api/v1/connections/{id}/execute`
(et l'outil MCP `execute_statement`, visible des seuls jetons qui écrivent) ; `/query` et `run_query` restent en lecture seule pour
tous. Un destructif (UPDATE/DELETE sans WHERE, DROP, TRUNCATE…) répond 409 `confirmation_required` tant que `confirmed: true` manque ;
sans aucun droit d'écriture, 403 `permission_denied`. Limite connue : seul le filtre lexical sépare données et structure
(un SELECT d'une fonction qui écrit n'est pas retenu en lecture-écriture ; les droits du compte de la base cible restent le dernier rempart).
`POST /api/v1/connections` (outil MCP `create_connection`, `TokenConnections`) enregistre une connexion chiffrée par la clé du jeton,
sous la même règle d'adresses que l'interface web (`TargetHostPolicy`), l'ajoute à la liste du jeton et journalise `connection.created` (acteur api/mcp).
Un jeton peut naître sans connexion s'il est « toutes » ou autorisé à en créer. Créations plafonnées à 20 par heure et par jeton (limiteur `api_connection_creation`), tentatives refusées comprises : 429 `too_many_attempts`.
Un jeton peut être émis pour « toutes mes connexions » (`allConnections: true` à la création, colonne `all_connections`) : la
liste est celle du compte à cet instant, puis ne bouge que par `POST /web/tokens/{id}/refresh` (`ApiTokenService::refresh`,
session requise, texte du jeton inchangé, connexions supprimées retirées, pas de plafond de connexions, refusé 422
pour un jeton à liste fixe ; l'actualisation ne prolonge pas l'expiration). Jamais automatique : le propriétaire décide quand les nouvelles connexions deviennent lisibles (audit `token.refreshed`).

## ClickHouse (src/Driver/ClickHouse)

Pas de pilote DBAL pour ClickHouse : `ClickHouseHttp` parle son interface HTTP (`symfony/http-client`), port 8123 par défaut
(8443 et 443 = TLS, déduit du port faute d'option dédiée). L'instruction part dans le corps d'un POST, les réglages et les
paramètres liés (`{nom:Type}`) dans l'adresse, les identifiants dans les en-têtes `X-ClickHouse-User/Key` (jamais dans une
URL). Réponse `JSONCompact` ; `JSON_BIGINT_AS_STRING` garde les UInt64 démesurés en texte. Erreurs normalisées
(`ClickHouseException` : code du moteur, message nettoyé du « Code: … (version …) », jamais d'adresse).
`ClickHouseQueryRunner` : lecture seule = réglage `readonly=1` imposé par le moteur (refuse écritures, DDL, `SETTINGS`,
fonctions de table sortantes comme `url()`/`remote()`) ; délai = `max_execution_time` (secondes fractionnaires, code 159 ->
`QueryTimeoutException`) ; limite de lignes = `max_result_rows=maxRows+1` + `result_overflow_mode=break` + `max_block_size`
ramené à la limite, le surplus est écarté ici. Pas de transaction : une écriture est immédiate. Les fonctions de table qui
sortent du serveur (`file`, `url`, `s3`, `remote`, `mysql`…) sont refusées par le garde seulement quand elles sont APPELÉES
(`calls()` du scanner), et la clause `FORMAT` aussi (le runner impose son format). Le dialecte du scanner (`SqlDialect::ClickHouse`) :
antislash dans chaînes ET identifiants, `--` sans espace, `#` commentaire, pas de commentaire exécutable, guillemets dollar ;
les commentaires `/* */` sont lus sans imbrication (ClickHouse les imbrique : la lecture est plus stricte, jamais plus laxiste).
`ClickHouseIntrospector` : `system.tables/columns/data_skipping_indices` ; pas de clé étrangère, et la clé de tri est un index
non unique nommé « ORDER BY » (« PRIMARY KEY » si différente), jamais une clé primaire relationnelle ; lignes = `total_rows`.
Plan d'exécution par `EXPLAIN json=1, indexes=1` lu par `ClickHousePlanParser` (ni coût ni lignes dans ce format : `PlanAnalyzer` ne
signale donc rien ; une lecture dont la clé de tri ne restreint rien est un parcours complet, sinon « PRIMARY KEY » + condition) ; pas de
règle d'audit (relationnelles). Redis garde le 501 `plan_unavailable` (bouton masqué par `ENGINE_HAS_PLAN`). Cible de test `target-clickhouse` (`TARGET_CLICKHOUSE_URL`, port hôte 18123) dans le profil `targets`.

## Redis (src/Driver/Redis, src/Core/KeyValue, src/Core/Guard/Redis*)

Pas un moteur SQL (`Engine::speaksSql()` est faux) : une commande Redis est une « instruction » du même pipeline (garde ->
exécuteur -> audit), donc console, API à jetons et MCP fonctionnent sans plomberie propre ; seul le schéma de tables manque
(introspecteur absent : /tables, /diagram, /audit, /plan rendent 501). Client `predis/predis` (pur PHP, l'image n'a pas
ext-redis) ; PHPStan veut `--memory-limit=512M`. Paramètres de connexion : « base » = numéro de base (0-255, vérifié à
l'ouverture), « identifiant » = utilisateur ACL (`default` sans ACL), port 6380 = TLS. `RedisCommandLine` découpe le texte
comme redis-cli (guillemets, `\xHH`, une commande par ligne) : le garde juge les ARGUMENTS qui partiront, pas le texte.
`RedisCommandGuard` = LISTE BLANCHE (lectures, puis écritures en mode lecture-écriture) ; refus nets : KEYS (SCAN le remplace),
commandes bloquantes ou de flux (dont XREAD : le serveur le classe bloquant), scripts, modules, CONFIG/ACL/CLIENT/DEBUG,
réplication, AUTH, FLUSHALL ; FLUSHDB demande confirmation. `RedisQueryRunner` : avant d'envoyer en lecture seule, interroge
`COMMAND INFO commande` et `commande|sous-commande` et refuse ce que le SERVEUR marque write/admin/blocking/pubsub (le serveur
fait foi ; un test vérifie que toute lecture de la liste blanche passe ce contrôle). Un client Predis NEUF par commande :
le délai de lecture (`read_write_timeout`) n'est pas modifiable sur une connexion ouverte ; `Predis\TimeoutException` ->
`QueryTimeoutException`. Réponse -> tableau (`value` ; HGETALL = field/value ; INFO = section/key/value ; imbriqué = JSON).
`RedisKeyValueStore` : SCAN (jamais KEYS, tranches <= 1000), TYPE + TTL en pipeline, lectures bornées par type (HSCAN/SSCAN,
LRANGE, ZRANGE, XRANGE COUNT, GETRANGE 64 Kio), noms de clés non UTF-8 laissés de côté. Web : `GET /web/connections/{id}/keys`
(`pattern`, `cursor`, `count` <= 1000) et `GET .../key?name=&limit=` (nom en paramètre de requête : il peut contenir `/`, `?`,
de l'unicode). Front : `KeyPane` (les clés se parcourent dans l'arbre de la barre latérale), la console réutilise `QueryPane` (pas de plan : `ENGINE_HAS_PLAN`).
Cible de test `target-redis` (`TARGET_REDIS_URL`, base 15, hôte 63791) : `RedisTarget::seeded()` vide et remplit la base 15
(refuse toute autre base).

## Diagnostics du serveur (src/Core/Diagnostics, src/Driver/*/…SessionDiagnostics, docs/api.md)

Socle de la supervision (issue #70) : `TargetConnection::diagnostics()` rend un `ServerDiagnostics` (une petite interface par thème, aujourd'hui
`SessionDiagnostics` ; absent = `UnsupportedOperationException` 501). JAMAIS par la console ni le garde : requêtes de catalogue fixes, bornées (200
lignes + `truncated`), dans une transaction en lecture seule annulée (`DbalCatalogReader`) ; rien ne s'écrit (KILL, SET… ne sont jamais lancés).
Un résultat porte un `DiagnosticStatus` (`Availability` : `available | unsupported | permission_denied | disabled | error`, `reason` = code stable,
`privilege` = mot-clé de droit) : un droit manquant ou une source éteinte est un ÉTAT, jamais une erreur ni une liste vide ; échec imprévu =
`DiagnosticsFailedException` (502 `diagnostics_failed`, message du moteur jamais montré) sur la route d'un thème ; dans la carte des capacités, `ServerDiagnostics::capabilities()` isole chaque thème (`DiagnosticStatus::failed()` = `error` / `check_failed`, cause consignée par `AuditedDiagnostics::capabilities` : thème + classe de l'exception, jamais le message). Sessions : MySQL `performance_schema.processlist` (état `disabled` / `performance_schema_off` si le serveur l'a désactivé : la table se lit alors vide, vérifié) et MariaDB `information_schema.PROCESSLIST` (sa seule source) (privilège
`PROCESS` lu dans `USER_PRIVILEGES` d'abord, car sans lui le serveur ne montre que les threads du compte sans le dire ; un droit reçu par un rôle n'est pas vu),
PostgreSQL `pg_stat_activity` (`pg_read_all_stats`/`pg_monitor`/superutilisateur, `backend_type = 'client backend'`), ClickHouse `system.processes`
(`readonly=1`, `query_id` choisi pour repérer sa propre ligne, code 497 = droit refusé), Redis `ServerDiagnostics` vide (capacités `unsupported`, sessions 501).
`ServerSession::query` passe par `QueryText::of` À LA CONSTRUCTION : littéraux (chaînes, `$$…$$`, `E'…'`, nombres) remplacés par `?` et commentaires retirés d'après `SqlScanner` (par dialecte, zones rendues par son paramètre `$spans`), PUIS raccourci à 200 caractères ; texte illisible pour le scanner (coupé au milieu d'un littéral) = `query` nul + `queryReason: redacted` (les pilotes lisent 4096 caractères pour masquer avant de couper) ; test d'intégration avec une vraie autre session sur les 5 moteurs + ClickHouse et n'entre JAMAIS dans l'audit :
`AuditedDiagnostics` (trois portes) journalise `diagnostic.read` = sujet, `topic`, `engine`, `status`, `rows`. Droit de jeton `diagnostic`
(`TokenPermissions`, colonne `diagnostic`, refusé par défaut, lecture seule, ne compte pas dans `canWrite`) : `ApiTokenAccess::requireDiagnostic` -> 403
`permission_denied` ; routes `GET /web/connections/{id}/diagnostics[/sessions]` (`?database=`/`?schema=`), `GET /api/v1/connections/{id}/diagnostics[/sessions]`,
outil MCP `list_sessions` (visible des seuls jetons `diagnostic`). Front : seulement `api/diagnostics.ts` (client typé), la case du jeton et le libellé d'audit ;
les pages sont les issues suivantes. Tests : `tests/Core/Diagnostics`, `tests/Integration/Driver/SessionDiagnosticsTest` (6 cibles + compte réduit créé/supprimé),
`tests/Integration/Web/DiagnosticsWebTest`.
Activité, transactions et verrous (issue #71) : `LockDiagnostics` (`status()`, `locks()` -> `LockReport` = transactions ouvertes `OpenTransaction` + attentes `LockWait` à plat, `LockReport::tree()` = `BlockingTree` « qui bloque qui » : racines = ceux qui bloquent sans attendre ; chaque session n'apparaît qu'UNE fois, sous un seul bloqueur (le détenteur, sinon le dernier devant elle dans la file : les moteurs listent TOUS les bloqueurs, le graphe est complet et l'ancien dépliage de chaque chemin faisait 2^k nœuds) ; cycle = sa plus petite session devient racine ; bornes `BlockingTree::MAX_NODES` 500 / `MAX_DEPTH` 20, `BlockingForest::$truncated` -> `blockingTruncated`). `QueryText::of` est le masquage + raccourcissement commun (littéraux masqués, 200 car., sessions, transactions, parties d'une attente ; `queryReason: redacted` si illisible). PostgreSQL n'appelle `pg_blocking_pids` que pour les sessions en `wait_event_type = 'Lock'`. La session qui lit n'est jamais listée comme transaction. PostgreSQL : `pg_stat_activity` + `pg_blocking_pids` (le serveur calcule l'attente) + `pg_locks` pour le mode et la table. MySQL 8.4/26 : `INNODB_TRX` (PROCESS) + `performance_schema.data_lock_waits/data_locks` + verrous de métadonnées `sys.schema_table_lock_waits` ; `@@performance_schema = 0` = `disabled` `performance_schema_off`, `SELECT` sur performance_schema ou `SELECT`+`EXECUTE` sur sys manquants = `permission_denied` (erreurs 1142/1356/1227 lues comme un état). MariaDB 11.8/13 : `INNODB_TRX` + `INNODB_LOCK_WAITS`/`INNODB_LOCKS` (pas de `data_locks`) ; ses verrous de métadonnées ne sont PAS rapportés (il faudrait performance_schema, éteint par défaut). ClickHouse et Redis : `locks` = `unsupported` (501). `GET /web/connections/{id}/diagnostics/locks`, `GET /api/v1/connections/{id}/diagnostics/locks`, outil MCP `list_locks` (droit `diagnostic`) ; audit `diagnostic.read` `topic: locks` + nombres seulement. Aucun KILL n'est proposé ni exécuté. Front : `ServerActivityPane` (route `server-activity`, entrée « Activité » de la barre de vues, absente sur Redis) : sessions, transactions ouvertes (seuil de « longue » = 60 s, réglable, non enregistré, mise en évidence des « en sommeil »), arbre de blocage ; actualisation manuelle ; états expliqués par `utils/diagnostics.describeStatus`. Tests : `tests/Core/Diagnostics`, `tests/Integration/Driver/LockDiagnosticsTest` (vrai blocage : `LockScenario` = une transaction tient une ligne, une seconde attend dans un sous-processus `lock_waiter.php`, table jetable).

Requêtes coûteuses par empreinte (issue #75) : `StatementDiagnostics` (`status()`, `top(DigestQuery)` -> `DigestReport` = état + `StatementDigest` : texte normalisé par le moteur PUIS masqué par `QueryText::of` (dialecte du moteur ; `pg_stat_statements` ne normalise PAS les instructions utilitaires : `ALTER ROLE … PASSWORD '…'` y est en clair ; `$1` reste une marque ; illisible = `text` nul + `textReason: redacted`) et raccourci, appels, durées en MILLISECONDES, lignes examinées / renvoyées + `ratio()`, sans index, tables temporaires, tris par fusion, première / dernière vue ; nul quand la source ne le dit pas). `DigestQuery::fromInput` borne le classement (liste fermée `DigestOrder` : `total_time` défaut, `mean_time`, `calls`, jamais un nom de colonne reçu) et la limite (1 à 100, 20 par défaut) et la fenêtre `hours` (1 à 168, 24 par défaut, lue par ClickHouse seulement : `system.query_log` filtré sur `event_date`/`event_time`, `windowHours` dans la réponse) : 422 `invalid_input`. MySQL/MariaDB `performance_schema.events_statements_summary_by_digest` (picosecondes -> ms ICI seulement ; éteint = `disabled` `performance_schema_off`, MariaDB le livre éteint ; SELECT manquant = `permission_denied`) ; PostgreSQL `pg_stat_statements` (`extension_missing` / `library_not_loaded` = `disabled` sans rien exécuter pour l'allumer, SQLSTATE 55000 lu comme un état ; `pg_read_all_stats` sinon texte masqué = `permission_denied` ; base de la connexion seulement ; pas de lignes examinées, `stats_since` dès PG 17) ; ClickHouse `system.query_log` (QueryFinish groupé par `normalized_query_hash`, `normalizeQuery` du serveur masque aussi les identifiants contenant un chiffre, tables système exclues, `readonly=1` ; code 60 = `query_log_off`) ; Redis `unsupported` (SLOWLOG non fait). `GET /web/connections/{id}/diagnostics/queries?order=&limit=`, `GET /api/v1/connections/{id}/diagnostics/queries`, outil MCP `top_queries` (droit `diagnostic`) ; capacité `statements` ; audit `diagnostic.read` `topic: top_queries` + classement, limite, état, nombre, jamais un texte. Aucune remise à zéro de la fenêtre (écriture). Front : onglet « Requêtes coûteuses » de `ServerActivityPane` (`TopQueriesPanel`, lu à l'ouverture de l'onglet, rapport >= 1 000 surligné, commande d'activation à LIRE par `utils/diagnostics.enableHint`). PAS de « Voir le plan » : un texte d'empreinte porte des `?` à la place des valeurs, il faut une saisie de valeurs avant `PlanService` (à faire à part). Tests : `tests/Core/Diagnostics`, `tests/Driver/ClickHouse/ClickHouseStatementDiagnosticsTest`, `tests/Integration/Driver/StatementDiagnosticsTest` (le cas PostgreSQL nominal est SAUTÉ tant que la cible n'a pas `pg_stat_statements` : `shared_preload_libraries` + `CREATE EXTENSION` ; l'état « éteint » est testé en réel).

## Optimiseur local (src/Core/Optimizer)

Sans PMM ni service externe : tout part de l'introspection et de `EXPLAIN`. `SchemaAuditor` (règles pures : table sans
clé primaire, clé étrangère sans index couvrant — colonnes en tête de l'index, ordre indifférent —, index redondant ;
jamais un index unique ; clé étrangère dont la colonne doit être CONVERTIE pour être comparée à la colonne référencée, donc sans usage de son index : PostgreSQL seulement, entier vers numeric ou flottant, char(n) vers text — entier vers bigint ou varchar vers text gardent l'index et ne sont pas signalés, mesuré) rend des `Finding` avec une suggestion SQL à relire, jamais exécutée par TerrilDB
(`GET /web/connections/{id}/audit`, 300 tables au plus). `PlanService` ne construit l'EXPLAIN que d'une lecture simple
(garde d'abord) et lit le JSON de PostgreSQL / MySQL / MariaDB / ClickHouse (`PostgreSqlPlanParser`, `MySqlPlanParser`, `ClickHousePlanParser`, fixtures
réelles dans `tests/Fixtures/plans`) vers un arbre commun `PlanNode` ; `PlanAnalyzer` ne signale qu'un parcours complet
d'une table estimée à 1000 lignes ou plus. EXPLAIN n'exécute pas la requête. `POST /web/connections/{id}/plan`, journalisé
avec l'instruction de l'utilisateur (`purpose: plan`). Les statistiques d'un moteur peuvent être périmées (MySQL annonce
1 ligne tant que la table n'est pas analysée) : on rapporte ce que le moteur dit.

## Diagramme ER (SchemaDiagramController, assets/utils/erLayout.ts, DiagramPane)

`GET /web/connections/{id}/diagram` : tables (pas les vues, 300 au plus) avec colonnes (type, nullable, clé primaire) et
relations = clés étrangères, tirées de l'introspection. La mise en page est côté front, sans bibliothèque : `layoutDiagram`
range les tables sur une grille dans l'ordre d'un parcours en profondeur des clés étrangères (tables liées côte à côte,
isolées en dernier, indépendant de l'ordre d'entrée) et trace chaque relation de la ligne de la clé étrangère à celle de
la colonne référencée (auto-référence = boucle à droite). `DiagramPane` dessine le tout en SVG (route `diagram`).

## Comptes multiples et invitations (src/Invitation, Entity/Invitation, Entity/Role)

Deux rôles : `admin` (le premier compte d'une installation, et le plus ancien à la migration) et `member`. Le rôle ne
change que la gestion des invitations ; chacun garde ses connexions, chiffrées par sa propre clé, donc invisibles aux
autres (y compris à l'administrateur). Une invitation est un lien à usage unique `inv_<id>_<secret>` : le serveur n'en
garde que l'empreinte, elle expire (7 jours par défaut, 30 au plus) et se révoque. Le serveur n'envoie aucun e-mail : la
note est un simple repère, l'administrateur transmet le lien. `InvitationService::register` crée le compte (toujours
membre) et consomme l'invitation dans une seule transaction manuelle (`wrapInTransaction` fermerait l'EntityManager à la
première exception du domaine) ; une inscription refusée ne brûle pas l'invitation ; inconnue, utilisée, expirée ou
révoquée rendent la même erreur `invalid_invitation`. Routes : `GET|POST /web/invitations`, `DELETE /web/invitations/{id}`
(403 `forbidden` pour un membre), `POST /web/register` accepte `invitation` même quand l'inscription libre est fermée.
Front : `/security/invitations` (`InvitationsView`), `/register?invitation=…`.
Envoi par e-mail facultatif (`InvitationMailer`, `symfony/mailer`) : actif seulement si `TERRILDB_MAIL_FROM` ET `TERRILDB_PUBLIC_URL`
sont renseignés (avec `MAILER_DSN`) ; le lien vient de l'adresse publique configurée, jamais de l'en-tête Host. `POST
/web/invitations` accepte `email` : l'adresse est validée AVANT d'émettre (rien d'orphelin), sert à l'envoi puis n'est
conservée nulle part (ni base, ni audit, ni journal) ; un échec de transport garde l'invitation (`emailSent: false`, le lien
reste à transmettre à la main) ; envoi non configuré = 409 `mail_disabled`. `GET /web/invitations` dit `emailEnabled`.

## Édition de structure (src/Core/Schema/Change, TableStructureController)

Modifier colonnes, index, clé primaire, clés étrangères, nom ou suppression d'une table SQL. Le client n'envoie JAMAIS de SQL : il envoie
la table telle qu'on la veut (`TableDraft` : chaque colonne, index ou clé étrangère garde son `originalName`, ce qui reconnaît un
renommage) ; le serveur relit la table réelle, `SchemaDiffer` déduit les opérations, un `DdlDialect` par moteur (PostgreSQL, MySQL +
MariaDB, ClickHouse) les écrit, `SchemaChangePlanner` rend le `ChangePlan` (instructions ordonnées : on retire d'abord clés
étrangères, index, clé primaire puis colonnes, on remet ensuite clé primaire, index, clés étrangères, le renommage de la table vient
en dernier). Le texte libre est borné : `ColumnType::validated` n'accepte que la FORME d'un type (mots, nombres, chaînes fermées,
parenthèses équilibrées, aucun mot de contrainte), `DefaultValue::parse` lit NULL, un nombre, une chaîne ou une courte liste
d'expressions (now(), uuid(), true…) et traite tout le reste comme une CHAÎNE échappée, jamais comme du SQL ; les noms passent par
`NameRule` puis sont échappés par le dialecte. Deux routes : `POST /web/connections/{id}/tables/{name}/structure/preview` (n'écrit rien :
instructions, `transactional`, `destructive`, `stale`) et `.../apply` (`fingerprint` obligatoire : empreinte de la structure rendue par la
lecture de la table, 409 `schema_changed` si la table a bougé entre-temps ; 409 `confirmation_required` + liste tant que `confirmed` manque
pour ce qui détruit : retirer colonne, index, clé, changer un type, supprimer la table ; 409 `schema_change_failed` avec `applied` et
`failed` ; 422 `invalid_schema_change`). `drop: true` à la place de `draft` supprime la table. Exécution par `TargetConnection::ddl()`
(`DbalDdlExecutor`, `ClickHouseDdlExecutor`) : PostgreSQL en UNE transaction (tout ou rien, `lock_timeout` 10 s), MySQL/MariaDB et
ClickHouse instruction par instruction (un échec laisse les précédentes appliquées, rapportées dans `applied`). Audit `schema.changed` /
`schema.failed` avec le SQL exact. Particularités : MySQL `MODIFY COLUMN` redit la définition (commentaire gardé, jeu de caractères
remis à celui de la table), colonne AUTO_INCREMENT nouvelle et sa clé dans un seul ALTER ; ClickHouse : nullabilité dans le type,
clé de tri en lecture seule, ni clé primaire ni clé étrangère ni auto-incrément ni renommage d'index, index de saut matérialisés ;
passer de Nullable à non-Nullable y exige une valeur par défaut. Seules les tables sont éditables (pas les vues), pas Redis.
Front : `TableEditor` (colonnes, index, clés étrangères modifiables sur place ; brouillon `utils/structureDraft` où colonnes, clé primaire, index et clés étrangères se réfèrent par identifiant donc un renommage les suit ; lignes nouvelle / modifiée / retirée surlignées ; barre collante en haut avec le SQL calculé par `preview`, boutons Valider / Annuler, confirmation qui liste ce qui sera perdu ; « Supprimer la table » avec le SQL montré), seulement pour les tables (une vue reste en lecture seule), la section Index est un tableau façon phpMyAdmin (une ligne par colonne avec son rang, nom / type / unique en cellules fusionnées, monter, descendre, ôter, ajouter une colonne ; la clé primaire en est la première ligne, la clé de tri ClickHouse une ligne verrouillée) ; le menu « Vues de la connexion » garde une entrée « Structure » vers la dernière table ouverte (base et schéma compris) ; `TablePane` rafraîchit les listes de tables et l'arbre après application. Tailles : `IndexDefinition::$sizeBytes` et `TableDefinition::$dataBytes/$indexBytes` (octets sur disque, nuls si le moteur ne les donne pas ; HORS de l'empreinte, ils bougent sans que la structure change) : PostgreSQL `pg_relation_size` / `pg_table_size` / `pg_indexes_size` ; MySQL/MariaDB `information_schema.TABLES` pour les totaux et `mysql.innodb_index_stats` pour les index secondaires (lecture facultative, la clé primaire InnoDB est la table : pas de taille propre) ; ClickHouse `system.parts` (`bytes_on_disk`, `primary_key_size`) et `system.data_skipping_indices`. Le front montre une colonne « Taille » des index et « Données / Index / Total » sous le titre de la table. Pré-contrôle des verrous (#72, `LockDiagnostics::tableHolders`, `TableHolders`/`TableHolder`) : `preview` rend `lockCheck` (`{status, holders[]}`, nul pour ClickHouse/Redis ou sans instruction), les sessions qui tiennent la table (MySQL : `performance_schema.metadata_locks` accordés + `threads`, âge par `INNODB_TRX` ; PostgreSQL : `pg_locks` relation accordée + `pg_stat_activity`, exige `pg_read_all_stats`) ; session, utilisateur, âge, inactive, mode, JAMAIS le texte de leur requête. AVERTISSEMENT seulement, jamais un blocage ; tout échec de lecture ou source illisible = état (`disabled` : MariaDB sans performance_schema, qui est éteint par défaut ; `permission_denied`) ou `lock_check_failed`, jamais une erreur. Délai dépassé (`lock_wait_timeout` 10 s MySQL/MariaDB 1205, `lock_timeout` PostgreSQL 55P03) : `SchemaChangeFailedException::$lock` (`LockConflict`, lot #73) et `$holders` (relus APRÈS l'échec) ; la réponse porte le code stable `lock_timeout` (ou `deadlock`) avec `retryable`, `applied`, `failed`, `lockTimeout` et `lockCheck` (la lecture des détenteurs n'écrit pas `diagnostic.read` : le cœur n'a pas le contexte d'audit), l'éditeur dit « une transaction ouverte retient cette table » et nomme les sessions. Jamais de KILL. Pas encore : aperçu de l'algorithme INSTANT/INPLACE/COPY.
Tests : `tests/Core/Schema/Change` (générateurs), `tests/Integration/Driver/SchemaChangeTest` (rejoués contre les vrais moteurs),
`tests/Integration/Web/TableStructureWebTest`.

## Export (src/Core/Export, ExportController)

`CsvExporter` (RFC 4180, CRLF, UTF-8, BOM optionnel) et `JsonExporter` (tableau d'objets, colonnes dupliquées suffixées).
Le CSV neutralise les cellules qu'un tableur lirait comme une formule (`=`, `+`, `-`, `@`, tab, CR en tête) par une
apostrophe, sauf les nombres. `POST /web/connections/{id}/export` : lecture seule, mêmes garde-fous et même audit qu'une
exécution (`purpose: export`), 10 000 lignes par défaut, 100 000 au plus, en-tête `X-TerrilDB-Truncated` si coupé.

## Édition de données (src/Core/Data, TableDataController)

Même schéma que la structure : le client envoie des LIGNES (`updates` = valeurs d'origine + colonnes changées, `inserts` = valeurs,
`deletes` = valeurs d'origine), jamais du SQL. `DataChangePlanner` écrit les instructions du moteur avec des paramètres liés (`?`,
ou `{pN:Type}` pour ClickHouse) ; le texte montré (`display`) a les valeurs en clair mais n'est jamais exécuté. Une ligne se retrouve
par sa clé primaire PLUS les valeurs d'origine des colonnes modifiées (une ligne changée entre-temps n'est plus retrouvée) ; sans clé
(ClickHouse n'en a pas), par toutes ses colonnes comparables (JSON, binaire, tableaux exclus). Une valeur remise telle qu'elle était n'est
pas un changement (pour une colonne numérique, « 5.00 », « 5.0 » et « +5 » sont la même valeur, comparée en texte sans passer par un
flottant) ; si le moteur range la même valeur sous une autre écriture (MySQL annonce 0 ligne touchée), la ligne est recomptée dans la
transaction avant de parler de conflit. 500 changements au plus. `DataChangeService::preview` compte les lignes que chaque WHERE retrouve (il en faut
exactement UNE : sinon `ok: false`) ; `apply` refuse (409 `data_changed`) si ce n'est plus le cas, demande confirmation pour toute
suppression (409 `confirmation_required`, `reason: delete_rows`) puis exécute. PostgreSQL / MySQL / MariaDB : une transaction
(`DbalDataExecutor`), une modification ou suppression qui ne touche pas exactement une ligne annule tout. ClickHouse : pas de transaction,
instruction par instruction, et modifications / suppressions sont des MUTATIONS asynchrones (`ALTER TABLE … UPDATE/DELETE`) : elles ne se
voient pas tout de suite (`asynchronous: true`, l'interface le dit) ; un ajout est immédiat. Routes web seulement :
`POST /web/connections/{id}/tables/{name}/data/preview` et `.../apply` (ni API à jetons, ni MCP). Audit : `data.changed` (nombre de
modifications / ajouts / suppressions, colonnes touchées, SQL AVEC ses paramètres mais jamais les valeurs : une colonne secrète ne se
retrouve pas recopiée dans le journal) et `data.failed` (`row_changed` ou `engine_refused`, sans le message du moteur qui peut citer la valeur). Erreurs : 422 `invalid_data_change`, 409 `data_changed`,
409 `data_change_failed` (message du moteur). Front : vue « Données » (`TableDataPane`, route `data/:name`) = SQL modifiable + `DataGrid` ;
un onglet de requête par table (`QueryTab::table`), grille éditable seulement si la requête renvoie les colonnes de la table telles quelles
(clé primaire sélectionnée, ou toutes les colonnes sans clé) ET si l'instruction exécutée est une lecture simple de cette table (`isPlainReadOf` : SELECT … FROM table [alias] avec WHERE / ORDER BY / LIMIT, sans jointure, agrégat, DISTINCT, UNION ni sous-requête ; `QueryTab::lastSql` = ce dont le résultat est la réponse) ; brouillon `utils/dataDraft` ; bandeau « N changements en attente » avec
Vérifier (aperçu SQL) puis Appliquer ; relecture après application ; relancer la requête est bloqué tant que des changements attendent.
Tests : `tests/Core/Data`, `tests/Integration/Driver/DataChangeTest` (4 moteurs), `tests/Integration/Web/TableDataWebTest`.

## Audit (src/Audit, Entity/AuditEvent)

Journal d'événements immuables (table `audit_event`, insertion par DBAL hors unité de travail ORM). `AuditLog::record`
ne fait jamais échouer l'action en cours si l'écriture échoue (erreur consignée), mais refuse un détail qui ressemble
à un secret (`password`, `secret`, `token`, `code`, `key`) ou qui n'est pas un scalaire / une courte liste de chaînes.
`AuditedQueries` enveloppe `QueryService` pour les trois portes (web, API, MCP) : exécution, refus et échec sont
journalisés avec le texte de l'instruction ; une simple demande de confirmation ne l'est pas. Les connexions échouées
sont attribuées au compte visé, les blocages une fois par minute. Lecture : `/web/activity` (pagination par curseur,
filtre par catégorie) et `/web/connections/{id}/history`. Purge : `app:audit:purge [--days]`, conservation
`TERRILDB_AUDIT_RETENTION_DAYS` (365).

## Serveur MCP (src/Http/Mcp, docs/mcp.md)

SDK officiel `mcp/sdk` (0.9, expérimental : les révisions du protocole sont gérées par lui), confiné à `Http/Mcp`.
`POST|DELETE /mcp` derrière le même pare-feu `api` que l'API REST (jeton porteur) ; `McpServerFactory` rebâtit le serveur
à chaque requête (quatre outils de lecture déclarés explicitement, plus `execute_statement` et `create_connection` selon les droits du jeton, sessions du protocole sur fichiers dans le cache) ; `McpTools`
réutilise `TargetSession`/`QueryService` (lecture seule sauf `execute_statement`) ; `McpSessionBinding` lie une session MCP au jeton qui l'a ouverte.
Pas de flux serveur (GET → 405). Hôtes acceptés : `TERRILDB_ALLOWED_HOSTS`. Les arguments d'outil portent le nom exact du
paramètre PHP (camelCase : `maxRows`, `timeoutMs`).

## Front (assets/, Vue 3 + TypeScript + Vite + Pinia + CodeMirror 6)

SPA servie sous `/app/` : Vite compile dans `public/app/` (ignoré par git) et l'index sort sous le nom `spa.html`
(voir `vite.config.ts` : le serveur PHP intégré servirait un `index.html` sans passer par Symfony, donc sans en-têtes
de sécurité) ; `SpaController` le sert pour toute adresse `/app/…`, `SecurityHeadersListener` pose la CSP (scripts
`'self'` seulement, pas de script en ligne). `docker/php/router.php` est le routeur du serveur de développement.
Structure : `api/` (client `fetch` + en-tête CSRF, `ApiError` à code stable, `errors.ts` = messages français),
`stores/` (Pinia : session, connexions, espace de travail à onglets), `components/`, `views/`, `utils/`,
`styles/tokens.css` (jetons de design, direction « Atelier » : bleu encre = action, ambre = secret/chiffré ; thème sombre par défaut et thème clair sous `:root[data-theme='light']` ; aucune couleur en dur dans les composants, tout passe par les variables, y compris la coloration de CodeMirror). `stores/theme.ts` : automatique (suit le système) / clair / sombre, choix gardé dans `localStorage` (tolérant au refus), bouton dans `AppHeader`.
Barre latérale permanente À DROITE (`WorkbenchSidebar`, seule navigation : plus de panneau de tables à gauche, repliable, choix gardé dans `localStorage` tolérant au refus) sur toute l'espace de travail : `ConnectionTree` = arbre ARIA `tree`/`treeitem` (connexion > bases > tables cent par cent, clés pour Redis ; flèches, Début, Fin, Entrée, Espace ; actions par connexion au clic et au clavier `n`/`e`/Suppr) alimenté par le store `tree` (déplié et lu gardés pendant la session, pastille fermée/ouverte/injoignable, réponses périmées ignorées, `reset()` à la déconnexion). Page d'accueil `/workspace` (`workspace-home`) ; `/connections` reste l'écran de gestion. Une table d'une autre base s'ouvre avec `?database=` et `WorkspaceView` explore alors cette base. `SchemaTree`, `KeyBrowser` et `DatabasePicker` ont été supprimés (doublon avec l'arbre) ; une cible injoignable s'annonce en bandeau au-dessus du contenu. La page « Mes connexions » montre la couleur d'une connexion (pastille devant le nom) ; `WorkspaceView` porte un menu « Vues de la connexion » (Console, Diagramme, Audit du schéma ; absent sur Redis) qui garde `?database=`/`?schema=`, et diagramme comme audit lisent la base et le schéma explorés (`workspace.scopeSuffix()`) ; le diagramme se zoome (paliers 25 à 300 %, boutons, Ajuster, Ctrl + molette) et élargit toutes ses boîtes à la ligne « nom + type » la plus longue. Les bases vides sont masquées dans l'arbre (store `tree.probeDatabases` : à l'ouverture d'une connexion de plus d'une base, au plus 25 bases non système sont sondées une à une, une table demandée ou, sur PostgreSQL, la liste des schémas ; une sonde en échec ne cache rien ; ligne « Afficher n bases vides » pour les voir, une base ouverte reste visible). La recherche de tables/clés par motif n'existe plus (seule l'autocomplétion de l'éditeur connaît toutes les tables).
L'éditeur a un onglet « Historique » (`HistoryPanel`, alimenté par `/web/connections/{id}/history` et rafraîchi après chaque exécution une fois ouvert) ; `/security/activity` affiche le journal du compte (`utils/audit.ts` = libellés français). Une réponse `unauthenticated`/`locked` ramène à la connexion (`onSessionLost`). Une redirection `?redirect=` n'est suivie
que si elle est interne. Polices auto-hébergées (@fontsource : Fraunces pour les titres, Inter pour l'interface, JetBrains Mono pour le code), rien n'est chargé d'un tiers. Navigation d'une connexion : une ligne « Console / Diagramme / Audit du schéma », puis une ligne d'ONGLETS DE TABLES façon navigateur (`workspace.tableTabs`, gardés en mémoire seulement, une table = un onglet par base/schéma, × pour fermer, la vue où on l'a laissée est retenue), puis pour la table active une ligne « Structure / Données ». La page d'accueil est la liste des connexions (`/connections`, `/` et `/workspace` y redirigent). Tests Vitest à côté du code
(`*.test.ts`) ; les rejets simulés se font avec `mockRejectedValueOnce` (un `mockRejectedValue` persistant déclenche un
« unhandled rejection »).

## Déploiement (docker/prod, compose.prod.yaml, .github/workflows/ci.yml, docs/deploy.md)

CI sur les serveurs GitHub (php, front, integration) puis, sur `push` vers `main` seulement, un runner auto-hébergé dédié
(étiquette `terrildb`) lance `docker/prod/deploy.sh` : build de l'image (FrankenPHP, interface compilée, sans root),
`pg_dump`, migrations, relance avec contrôle de santé et retour à l'image précédente si KO. Secrets et domaine hors dépôt
(`~/.config/terrildb`, secret GitHub `TERRILDB_DOMAIN`) : ne jamais écrire le domaine ni un secret dans le dépôt, qui est
public. La base interne est seule sur le réseau Docker `backend` (`internal`, partagé avec l'application, aucune sortie) ; les règles de sortie du pare-feu de l'hôte sont documentées dans `docs/deploy.md` mais jamais appliquées par le script (elles dépendent des bases cibles). L'application n'écoute que sur 127.0.0.1 (port 8090) derrière le nginx de la machine (`setup-host.sh`, une fois,
sudo). Dépôt public + runner : les workflows de PR externes exigent une approbation, ne jamais approuver une PR qui touche
`.github/` ou `docker/` (règle pour les contributeurs extérieurs ; les PR de Dependabot, dont l'auteur est de confiance, se relisent : le diff ne doit toucher que des versions ou des empreintes d'actions).
Dependabot (`.github/dependabot.yml`) : mineures et correctifs groupés par écosystème, chaque MAJEURE a son PR ; les majeures de `postgres` (base interne de production) sont ignorées : un changement de version majeure demande un dump et une restauration à la main. Le bilan du lundi (`dependency-digest.yml`) crée la nouvelle issue AVANT de refermer l'ancienne. CI : l'image PHP (`terrildb-php`, nom fixé dans `compose.yaml`) est gardée d'un run à l'autre (`actions/cache` + `docker save`/`load`, clé = Dockerfile + semaine ISO) ; ClickHouse de test est épinglé sur une version (`26.10`), pas `latest`. Le job `changes` trie les fichiers modifiés (`.github/scripts/changes.sh`, essayé par `changes-test.sh`) : documentation seule = aucun contrôle ni déploiement ; `assets/` et fichiers npm = interface seulement ; `src/`, `tests/`, `config/`, `migrations/`, composer = back seulement (style, analyse, tests unitaires ET d'intégration) ; tout le reste (docker, compose, `.github`, fichier inconnu) = tout. Dans le doute tout tourne, y compris quand le dernier run de `main` n'était pas vert ou qu'un autre run de `main` est encore en cours (il peut finir rouge) ; le déploiement n'attend que les contrôles qui ont tourné (un contrôle sauté ne le bloque pas, un échec oui). Réglages GitHub du dépôt (hors fichiers, à refaire si on le recrée) : approbation obligatoire pour TOUT contributeur
extérieur (`all_external_contributors`), actions limitées à celles de GitHub et figées par empreinte, règle `main-protegee` (ni suppression
ni push forcé de `main` : la désactiver le temps d'une réécriture d'historique voulue), alertes de vulnérabilités, correctifs de sécurité
Dependabot, secret scanning et protection des pushs, signalement privé des failles. Les journaux d'Actions d'un dépôt public sont lisibles de
tous : le job de déploiement masque le compte et les chemins de la machine (`add-mask`), mais l'en-tête du job imprime le nom de la machine
(`Machine name`) AVANT toute étape : ce nom doit rester neutre (nom d'hôte et dossier du runner sans rapport avec un projet ou une personne).
Chaque job a un délai maximum (le déploiement occupe l'unique runner de production).

## Commandes (PHP/Composer/Node absents de l'hôte : tout passe par Docker)

```bash
sh docker/init-env.sh                                     # une fois : mots de passe locaux aléatoires (docker/dev/, ignoré)
docker compose up -d db                                   # base interne
docker compose --profile targets up -d                    # MySQL 8.4+26 / MariaDB 11.8+13 / PG 18 / ClickHouse / Redis de test
R="docker compose run --rm --no-deps php"
$R vendor/bin/phpunit                                     # tests
$R vendor/bin/phpstan analyse --no-progress --memory-limit=512M   # analyse statique (niveau max)
$R vendor/bin/php-cs-fixer fix                            # style
$R composer require <paquet>
docker compose --profile targets up -d --wait             # attend les healthchecks (init SQL terminée)
$R vendor/bin/phpunit --group integration                 # tests d'intégration (exclus de la suite par défaut)
docker compose --profile targets down -v                  # arrêt + remise à zéro (rejoue l'init SQL)
docker compose up php                                     # serveur sur :8000 (GET /api/health, interface sur /app/)
N="docker compose run --rm --no-deps node"
$N npm install                                            # dépendances front
$N npx vitest run                                         # tests front
$N npm run typecheck && $N npm run lint                   # vue-tsc, ESLint
$N npm run build                                          # compile dans public/app/ (à refaire pour voir un changement sur :8000)
docker compose --profile front up node                    # Vite en développement sur :5173/app/ (proxy vers le PHP)
$R php bin/console doctrine:migrations:migrate            # migrations de la base interne
$R php bin/console doctrine:database:create --env=test --if-not-exists   # base terrildb_test (une fois)
$R php bin/console doctrine:migrations:migrate --env=test # schéma de test (tests --group integration)
$R php bin/console doctrine:migrations:diff               # nouvelle migration après un changement d'entité
```

Les mots de passe des bases locales ne sont dans aucun fichier versionné : `sh docker/init-env.sh` (une fois après le clonage) les tire au hasard dans `docker/dev/*.env` (ignoré par git, lu par `compose.yaml` via `env_file`). L'URL de la base interne (`DATABASE_URL`) vient de là, jamais de `.env`. Changer ces fichiers après un premier démarrage impose `docker compose down -v` (les volumes gardent l'ancien mot de passe). Les tests d'intégration de
persistance (`tests/Integration/Persistence`) tournent dans une transaction annulée à chaque test.

## Bases cibles de test

Scripts d'init déterministes (SQL pur, sans aléatoire) dans `docker/targets/` : `mysql/` (partagé par
MySQL 8.4 et 26, MariaDB 11.8 et 13, même dialecte), `postgres/` et `clickhouse/` (même jeu, tables MergeTree sans clés étrangères). Schéma `shop` : `categories` (auto-FK),
`customers`, `products`, `orders`, `order_items` (7 000 lignes), index simples/composites/uniques et
vues `v_order_totals`, `v_product_sales`. Les tests d'intégration lisent `TARGET_MYSQL_URL`,
`TARGET_MYSQL26_URL`, `TARGET_MARIADB_URL`, `TARGET_MARIADB13_URL`, `TARGET_POSTGRES_URL`, `TARGET_CLICKHOUSE_URL`, `TARGET_REDIS_URL` (définies dans `docker/dev/php.env`, lu par le service `php`) et sont
sautés si la base est injoignable. Un changement de script impose `down -v` pour être rejoué.
MySQL et MariaDB ont DEUX cibles chacune (`target-mysql` 8.4 et `target-mysql-26`, `target-mariadb` 11.8 et `target-mariadb-13`) : la
série à support long, que des installations gardent des années, et la plus récente ; une série ne remplace pas l'autre. Les tests
par moteur (`TargetDatabase::all()`/`relational()`) les parcourent toutes, et `TargetVersionTest` garde chaque cible à sa série
(`SELECT VERSION()`). Dependabot ignore les majeures de `mysql` et `mariadb` : changer de série se fait à la main. Le pilote est le
même pour toutes les versions (un seul `MySqlDriver` ; les variantes se lisent à la forme des réponses, ex. plan JSON v1/v2) ;
`docker/init-env.sh` complète un `docker/dev/php.env` existant avec les variables ajoutées depuis.
Tous les ports de `compose.yaml` ne sont publiés que sur `127.0.0.1` (HTTP clair, bases aux mots de passe jetables ; `tests/ComposePortsTest`
le vérifie, donc une cible ajoutée doit suivre). Pour les ouvrir au réseau : `compose.override.yaml` (ignoré par git, propre à chaque machine) avec
`ports: !override ["8000:8000"]` : sans `!override`, ses ports s'ajoutent à ceux de base au lieu de les remplacer.

## Conventions PHP

`declare(strict_types=1)`, classes `final` (et `readonly` pour les DTO), accolades partout, pas de tableau
associatif nu en retour public, commentaires seulement si le *pourquoi* n'est pas évident.
