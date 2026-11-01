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
reste dans l'exception précédente, jamais montré à l'utilisateur). Drivers : `MySql` (MySQL + MariaDB, information_schema)
et `PostgreSql` (pg_catalog). Les introspecteurs rendent le modèle `Core/Schema` (types natifs du moteur, index
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
`styles/tokens.css` (jetons de design, thème sombre unique : teal = action, ambre = secret/chiffré).
Une réponse `unauthenticated`/`locked` ramène à la connexion (`onSessionLost`). Une redirection `?redirect=` n'est suivie
que si elle est interne. Polices auto-hébergées (@fontsource), rien n'est chargé d'un tiers. Tests Vitest à côté du code
(`*.test.ts`) ; les rejets simulés se font avec `mockRejectedValueOnce` (un `mockRejectedValue` persistant déclenche un
« unhandled rejection »).

## Commandes (PHP/Composer/Node absents de l'hôte : tout passe par Docker)

```bash
docker compose up -d db                                   # base interne
docker compose --profile targets up -d                    # MySQL 8.4 / MariaDB 11.8 / PG 18 de test
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

L'URL de la base interne (`DATABASE_URL`) vient de `compose.yaml`, jamais de `.env`. Les tests d'intégration de
persistance (`tests/Integration/Persistence`) tournent dans une transaction annulée à chaque test.

## Bases cibles de test

Scripts d'init déterministes (SQL pur, sans aléatoire) dans `docker/targets/` : `mysql/` (partagé par
MySQL 8.4 et MariaDB 11.8, même dialecte) et `postgres/`. Schéma `shop` : `categories` (auto-FK),
`customers`, `products`, `orders`, `order_items` (11 420 lignes), index simples/composites/uniques et
vues `v_order_totals`, `v_product_sales`. Les tests d'intégration lisent `TARGET_MYSQL_URL`,
`TARGET_MARIADB_URL`, `TARGET_POSTGRES_URL` (définies dans `compose.yaml`, service `php`) et sont
sautés si la base est injoignable. Un changement de script impose `down -v` pour être rejoué.

## Conventions PHP

`declare(strict_types=1)`, classes `final` (et `readonly` pour les DTO), accolades partout, pas de tableau
associatif nu en retour public, commentaires seulement si le *pourquoi* n'est pas évident.
