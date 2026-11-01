# CLAUDE.md — TerrilDB

Client / gestionnaire de bases de données web, auto-hébergé : entre phpMyAdmin et la console BDD de
PhpStorm. TerrilDB **n'est pas** un moteur : il se connecte à des bases existantes.
Trois entrées partageant le même cœur : **interface web**, **API REST**, **serveur MCP**.

## Règles non négociables

1. **TDD only** : test rouge d'abord, puis le code minimum pour le vert, puis refactor. Aucun code de
   production sans test qui l'a précédé. Un commit = un lot atomique (test + code).
2. **Dépôt GitHub privé** (compte `gh`), pour l'instant ; ne le rendre public que sur demande explicite.
   Aucune référence à un projet ou une organisation
   tiers dans le code, les docs, les tests ou l'historique.
3. **Dépôt potentiellement public** : aucune donnée personnelle visible (e-mail, nom réel, chemins
   absolus de la machine, secrets), ni dans les fichiers ni dans l'historique. Identité git = compte GitHub
   configuré avec `gh` (adresse `…@users.noreply.github.com`). Pour GitHub, utiliser `gh`.
4. **Zéro information de connexion dans le code** (hôte, base, identifiant, même placeholder).
5. **Secrets zero-knowledge** : la clé de chiffrement des identifiants de connexion est dérivée du mot de
   passe de l'utilisateur (libsodium) ; le serveur seul ne peut pas déchiffrer.
6. Dernières versions stables de langage et framework.
7. Un choix d'architecture non couvert ici : poser la question, ne pas deviner.

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
Lecture seule MCP/API : filtre lexical **et** transaction read-only côté moteur (le moteur fait foi).

## Périmètre

MVP (brief) d'abord, puis par lots : multi-utilisateur + invitations → optimiseur local (plan
d'exécution + règles d'index ; sans PMM/Vitess) → audit d'optimisation → ER diagram → Redis → ClickHouse.
Hors lots : grille éditable, diff de schémas, import CSV, assistant IA intégré.

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
connexion inexistante. `PasswordChanger` rechiffre TOTP et connexions ensemble, tout-ou-rien : une connexion
illisible bloque le changement.

## Introspection (src/Core/Driver, src/Core/Schema, src/Driver)

Un `Driver` (tagué `app.driver`, rassemblés dans `DriverRegistry`) sert un ou plusieurs `Engine` : il ouvre une
`TargetConnection` (DBAL + `SchemaIntrospector`). `DbalConnector` borne le délai de connexion et vérifie par un
`SELECT 1` ; un échec rend toujours `TargetConnectionFailedException` au message générique (le détail du moteur
reste dans l'exception précédente, jamais montré à l'utilisateur). Drivers : `MySql` (MySQL + MariaDB, information_schema),
`PostgreSql` (pg_catalog) et `ClickHouse` (voir plus bas). `TargetConnection` ne dépend d'aucune bibliothèque d'accès : le
driver lui donne l'introspecteur, le runner et la façon de se libérer. Les introspecteurs rendent le modèle `Core/Schema` (types natifs du moteur, index
primaire d'abord puis par nom, tri à l'octet car l'ordre du moteur dépend de la collation) ; périmètre = base de la
connexion (MySQL) ou schéma courant (PostgreSQL). Nombres de lignes = estimations du moteur, jamais un COUNT. Les noms
de tables passent toujours en paramètres liés. `TargetOpener` relie le tout : connexion enregistrée + clé -> cible ouverte.

## Exécution et garde-fous (src/Core/Guard, src/Core/Query, src/Driver/*/…QueryRunner)

`QueryService` est le point d'entrée unique (web, API, MCP) : `StatementGuard` examine, `QueryRunner` du moteur exécute.
Le garde (lexical, `SqlScanner` par dialecte) n'accepte qu'UNE instruction, refuse les constructs hors transaction
(INTO OUTFILE, FOR UPDATE, fonctions de fichiers, LOAD/COPY, SET/BEGIN/COMMIT…) et demande confirmation pour ce qui
détruit (UPDATE/DELETE sans WHERE de même niveau, DROP, TRUNCATE, ALTER, GRANT, CALL). Un refus est définitif, aucune
confirmation ne le lève. `QueryMode::ReadOnly` par défaut ; l'API et le MCP n'en ont pas d'autre. Le filtre peut se
tromper : le moteur fait foi. PostgreSQL : `BEGIN READ ONLY` + `statement_timeout` local + curseur serveur (limite de
lignes). MySQL/MariaDB : `SET SESSION TRANSACTION READ ONLY` (et non `START TRANSACTION READ ONLY`, qui laisse passer
le DDL), `MAX_EXECUTION_TIME` / `max_statement_time`, lecture non tamponnée, instructions multiples désactivées. Une
lecture est toujours annulée ; une écriture est validée si tout réussit. Limites connues : MySQL n'a pas de délai moteur
pour une écriture ; un DDL MySQL en lecture-écriture valide implicitement ; l'opérateur `?` de jsonb ne passe pas dans
EXPLAIN/SHOW sur PostgreSQL (il passe dans les SELECT).

## Couche web (src/Http/Web)

API JSON de la SPA, routes `/web/*` (l'API REST à jetons sera `/api/v1/*`). Contrôleurs minces : `AccountController`
(inscription, connexion, `/web/me`), `AccountSecurityController` (mot de passe, TOTP, codes de récupération),
`ConnectionController`, `SchemaController`, `QueryController`. La connexion n'est pas faite par un authentificateur
Symfony mais par `LoginService` (qui dérive la clé) ; `WebSession::open` démarre la session, renouvelle son identifiant,
ouvre la session Symfony (`ProgrammaticAuthenticator`) et confie la clé au coffre : toujours démarrer la session AVANT de
lire son identifiant. Une session perd sa reconnaissance quand le hash du mot de passe change (`SecurityUser::isEqualTo`).
Protections : en-tête `X-TerrilDB-Request: 1` obligatoire sur toute requête non sûre (CSRF), plafond de tentatives
(`LoginThrottle` : 30/15 min par adresse, 8/15 min par compte ; aussi sur les actions qui vérifient le mot de passe),
erreurs du domaine traduites en codes stables par `ErrorMapper` (jamais le message du moteur d'une cible injoignable).
Inscription : `APP_REGISTRATION=first_only` (défaut, seulement le premier compte) ou `open`.
Tests fonctionnels : `tests/Integration/Web/WebApiTestCase` (conteneur redémarré à chaque requête, écritures annulées par DAMA).

## API REST à jetons (src/ApiToken, src/Http/Api, docs/api.md)

Le serveur ne détient jamais la clé de l'utilisateur ; un jeton d'API (`tdb_<id>_<secret>`) emporte donc une copie de
la clé enveloppée par son propre secret (`SessionKeyWrapper`, contexte = id du jeton). Le serveur ne garde que
l'empreinte du secret (`ApiTokenSecret::verifier`) et l'enveloppe : sans le jeton, rien n'est lisible. Lecture seule
uniquement, connexions listées (au moins une), expiration obligatoire (365 jours au plus), supprimé à tout changement
de mot de passe (`PasswordChanger` → `ApiTokenService::revokeAll`, même transaction). Pare-feu `api` sans session
(`BearerTokenAuthenticator`) ; échecs plafonnés par adresse, requêtes plafonnées par jeton (`ApiThrottle`). Les
assistants JSON partagés avec l'interface web sont dans `App\Http\Json`.

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
Pas de plan d'exécution (`PlanNotAvailableException` -> 501 `plan_unavailable`, bouton masqué par `ENGINE_HAS_PLAN`) et pas de
règle d'audit (relationnelles). Cible de test `target-clickhouse` (`TARGET_CLICKHOUSE_URL`, port hôte 18123) dans le profil `targets`.

## Optimiseur local (src/Core/Optimizer)

Sans PMM ni service externe : tout part de l'introspection et de `EXPLAIN`. `SchemaAuditor` (règles pures : table sans
clé primaire, clé étrangère sans index couvrant — colonnes en tête de l'index, ordre indifférent —, index redondant ;
jamais un index unique) rend des `Finding` avec une suggestion SQL à relire, jamais exécutée par TerrilDB
(`GET /web/connections/{id}/audit`, 300 tables au plus). `PlanService` ne construit l'EXPLAIN que d'une lecture simple
(garde d'abord) et lit le JSON de PostgreSQL / MySQL / MariaDB (`PostgreSqlPlanParser`, `MySqlPlanParser`, fixtures
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

## Export (src/Core/Export, ExportController)

`CsvExporter` (RFC 4180, CRLF, UTF-8, BOM optionnel) et `JsonExporter` (tableau d'objets, colonnes dupliquées suffixées).
Le CSV neutralise les cellules qu'un tableur lirait comme une formule (`=`, `+`, `-`, `@`, tab, CR en tête) par une
apostrophe, sauf les nombres. `POST /web/connections/{id}/export` : lecture seule, mêmes garde-fous et même audit qu'une
exécution (`purpose: export`), 10 000 lignes par défaut, 100 000 au plus, en-tête `X-TerrilDB-Truncated` si coupé.

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
à chaque requête (quatre outils déclarés explicitement, sessions du protocole sur fichiers dans le cache) ; `McpTools`
réutilise `TargetSession`/`QueryService` en lecture seule ; `McpSessionBinding` lie une session MCP au jeton qui l'a ouverte.
Pas de flux serveur (GET → 405). Hôtes acceptés : `TERRILDB_ALLOWED_HOSTS`. Les arguments d'outil portent le nom exact du
paramètre PHP (camelCase : `maxRows`, `timeoutMs`).

## Front (assets/, Vue 3 + TypeScript + Vite + Pinia + CodeMirror 6)

SPA servie sous `/app/` : Vite compile dans `public/app/` (ignoré par git) et l'index sort sous le nom `spa.html`
(voir `vite.config.ts` : le serveur PHP intégré servirait un `index.html` sans passer par Symfony, donc sans en-têtes
de sécurité) ; `SpaController` le sert pour toute adresse `/app/…`, `SecurityHeadersListener` pose la CSP (scripts
`'self'` seulement, pas de script en ligne). `docker/php/router.php` est le routeur du serveur de développement.
Structure : `api/` (client `fetch` + en-tête CSRF, `ApiError` à code stable, `errors.ts` = messages français),
`stores/` (Pinia : session, connexions, espace de travail à onglets), `components/`, `views/`, `utils/`,
`styles/tokens.css` (jetons de design : teal = action, ambre = secret/chiffré ; thème sombre par défaut et thème clair sous `:root[data-theme='light']` ; aucune couleur en dur dans les composants, tout passe par les variables, y compris la coloration de CodeMirror). `stores/theme.ts` : automatique (suit le système) / clair / sombre, choix gardé dans `localStorage` (tolérant au refus), bouton dans `AppHeader`.
L'éditeur a un onglet « Historique » (`HistoryPanel`, alimenté par `/web/connections/{id}/history` et rafraîchi après chaque exécution une fois ouvert) ; `/security/activity` affiche le journal du compte (`utils/audit.ts` = libellés français). Une réponse `unauthenticated`/`locked` ramène à la connexion (`onSessionLost`). Une redirection `?redirect=` n'est suivie
que si elle est interne. Polices auto-hébergées (@fontsource), rien n'est chargé d'un tiers. Tests Vitest à côté du code
(`*.test.ts`) ; les rejets simulés se font avec `mockRejectedValueOnce` (un `mockRejectedValue` persistant déclenche un
« unhandled rejection »).

## Commandes (PHP/Composer/Node absents de l'hôte : tout passe par Docker)

```bash
sh docker/init-env.sh                                     # une fois : mots de passe locaux aléatoires (docker/dev/, ignoré)
docker compose up -d db                                   # base interne
docker compose --profile targets up -d                    # MySQL 8.4 / MariaDB 11.8 / PG 18 / ClickHouse de test
R="docker compose run --rm --no-deps php"
$R vendor/bin/phpunit                                     # tests
$R vendor/bin/phpstan analyse --no-progress               # analyse statique (niveau max)
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
MySQL 8.4 et MariaDB 11.8, même dialecte), `postgres/` et `clickhouse/` (même jeu, tables MergeTree sans clés étrangères). Schéma `shop` : `categories` (auto-FK),
`customers`, `products`, `orders`, `order_items` (11 420 lignes), index simples/composites/uniques et
vues `v_order_totals`, `v_product_sales`. Les tests d'intégration lisent `TARGET_MYSQL_URL`,
`TARGET_MARIADB_URL`, `TARGET_POSTGRES_URL`, `TARGET_CLICKHOUSE_URL` (définies dans `docker/dev/php.env`, lu par le service `php`) et sont
sautés si la base est injoignable. Un changement de script impose `down -v` pour être rejoué.

## Conventions PHP

`declare(strict_types=1)`, classes `final` (et `readonly` pour les DTO), accolades partout, pas de tableau
associatif nu en retour public, commentaires seulement si le *pourquoi* n'est pas évident.
