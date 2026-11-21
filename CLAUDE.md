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

## Invariants par domaine (le détail est dans `docs/architecture/`, à lire AVANT de toucher au domaine)

Chaque fichier garde le détail livré par thème (sources par moteur, routes, états, tests). Ne pas y ajouter une section par PR : mettre à jour
le fichier du thème, et ne remonter ici qu'un invariant nouveau.

- **Secrets et connexions** (`secrets-connexions-introspection.md`) : la clé vient du mot de passe (Argon2id), jamais stockée en clair ; les paramètres de
  connexion sont chiffrés d'un bloc, cloisonnés par propriétaire ; `TargetHostPolicy` borne les hôtes visés, le nom n'est résolu qu'une fois (adresse épinglée) ;
  une erreur de connexion reste générique (le détail du moteur n'est jamais montré). Tunnel SSH : liste d'autorisation des bastions (`TERRILDB_SSH_ALLOWED_BASTIONS`) vide = tout refusé, clé privée chiffrée avec la clé de l'utilisateur et seulement en tmpfs, empreinte de l'hôte obligatoire, jamais de secret en clair ni de connexion directe en repli. `CapabilityMatrix` est la seule source de ce qu'un serveur sait faire.
- **Garde SQL et exécution** (`garde-et-scripts.md`) : `QueryService` est l'entrée unique ; lecture seule par défaut, filtre lexical ET transaction du moteur
  (le moteur fait foi) ; un refus du garde est définitif ; une seule instruction ; les scripts sont jugés en entier avant la première exécution.
- **Web, API à jetons, MCP, comptes, audit** (`web-api-mcp-comptes.md`) : adaptateurs fins autour du cœur ; CSRF par en-tête, plafonds de tentatives ;
  jeton d'API = copie de clé enveloppée par son secret, droits refusés par défaut, écriture en deux temps ; l'audit n'enregistre jamais un secret et ne fait pas échouer l'action.
- **Moteurs** (`moteurs.md`) : ClickHouse (HTTP, `readonly=1`) et Redis (liste blanche de commandes, `COMMAND INFO` du serveur) passent par le même pipeline garde -> exécuteur -> audit ; différences MySQL/MariaDB mesurées.
- **Diagnostics** (`diagnostics-et-noms.md`) : requêtes de catalogue fixes, lecture seule, bornées ; un droit manquant est un ÉTAT, jamais une erreur ni une liste vide ;
  jamais de KILL/SET ; les textes d'instruction sont masqués par `QueryText::of` ; aucune empreinte de mot de passe lue. Tout nom lu dans une cible est hostile :
  le citer dans du SQL passe TOUJOURS par `SqlText::quoteIdentifier` (et `commentSafe` en commentaire), jamais par `sprintf`.
- **Optimiseur** (`optimiseur-et-diagramme.md`) : règles pures sur le modèle d'introspection ; jamais d'`ANALYZE` ni d'exécution d'une suggestion ; une source illisible est « non vérifié », pas une erreur ; le linter ne juge que le texte, zéro faux positif plutôt que couverture.
- **Structure, données, export** (`structure-donnees-export.md`) : le client n'envoie jamais de SQL, seulement la table ou les lignes voulues ; aperçu puis application avec empreinte ;
  confirmation pour tout ce qui détruit ; texte libre borné (`ColumnType`, `DefaultValue`, `NameRule`) ; ce que le modèle ne sait pas redire est verrouillé, jamais perdu en silence.
- **Front et déploiement** (`front-et-deploiement.md`) : aucune couleur en dur (jetons CSS), CSP sans script en ligne ; la fusion sur `main` déploie en production (runner dédié) :
  ni domaine ni secret dans le dépôt public, ne jamais approuver une PR externe qui touche `.github/` ou `docker/`.

## Commandes (PHP/Composer/Node absents de l'hôte : tout passe par Docker)

```bash
sh docker/init-env.sh                                     # une fois : mots de passe locaux et APP_SECRET aléatoires (docker/dev/, ignoré)
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

Les mots de passe des bases locales et l'`APP_SECRET` de développement ne sont dans aucun fichier versionné (plus de `.env.dev` ; `.env.test` garde une valeur de test non secrète) : `sh docker/init-env.sh` (une fois après le clonage) les tire au hasard (`APP_SECRET` = 64 caractères hexadécimaux dans `docker/dev/php.env`, ajouté sans rien changer à une installation existante qui n'en a pas, jamais réécrit ; `tests/InitEnvScriptTest`) dans `docker/dev/*.env` (ignoré par git, lu par `compose.yaml` via `env_file`). L'URL de la base interne (`DATABASE_URL`) vient de là, jamais de `.env`. Changer ces fichiers après un premier démarrage impose `docker compose down -v` (les volumes gardent l'ancien mot de passe). Les tests d'intégration de
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
`docker/init-env.sh` complète un `docker/dev/php.env` existant avec les variables ajoutées depuis. Cible Vitess facultative (`target-vitess`, profil `vitess`, un seul `mysqld` derrière des tablets primaire/réplica/lecture seule : pas de vraie réplication, `TARGET_VITESS_URL`, seule cible de test SANS mot de passe : `vttestserver` n'a pas d'authentification réglable, `TargetDatabase` la nomme ; toute autre cible exige un mot de passe) : `docs/vitess-tests.md`. Vrai cluster (profil `vitess-cluster`, etcd + vtctld + deux tablets avec chacun leur `mysqld` répliqué + vtgate, `TARGET_VITESS_CLUSTER_URL`, vtgate à authentification statique, mot de passe de `docker/dev/target-vitess.env` tiré par `init-env.sh`, `TargetVitessClusterTest`, hors CI) : mêmes notes.

<<<<<<< HEAD
Vitess, lecture seule (#39, #40) : la correction vit dans `MySqlQueryRunner` (groupe A : `START TRANSACTION READ ONLY` TOUJOURS ouvert en lecture seule, sans détection de version ; voir « Exécution et garde-fous »). Ce dépôt la mesure sur le vrai cluster, `tests/Integration/Driver/VitessReadOnlyTest` : vtgate accepte `SET SESSION TRANSACTION READ ONLY` SANS le transmettre (`@@session.transaction_read_only` reste 0), le tablet refuse alors les écritures de DONNÉES (25006 / 1792) sur `shop` et `shop@primary`, mais un DDL s'exécute quand même : sur le primaire le garde est la SEULE barrière du DDL (un test verrouille cette limite) ; un `shop@replica` refuse tout par lui-même. `SET SESSION MAX_EXECUTION_TIME` : accepté, non appliqué ; `SET GLOBAL read_only` : accepté sans effet ; `GET_LOCK` mêlé à d'autres colonnes : refusé par vtgate. Coût : transaction coupée à 30 s (`--queryserver-config-transaction-timeout`). Lecture sur `@replica` : option de la connexion (`readReplica`, MySQL seulement) ; AVANT d'enregistrer, de modifier ou d'essayer une connexion qui la demande, `VitessReplicaCheck` ouvre la cible sur le PRIMAIRE, vérifie que c'est un vtgate et lit `SHOW VITESS_TABLETS` (`ReplicaAvailability` : `available` = un REPLICA en service pour le keyspace, `missing`, `not_vitess`, `unknown` = illisible, jamais un refus) : `missing` / `not_vitess` = 422 `replica_unavailable` (`reason` `no_replica` / `not_vitess`), rien n'est enregistré ; un essai de connexion MySQL rend aussi `replica` (information, interface : « un réplica en service peut recevoir les lectures » / « Aucun réplica en service »). Rappel hebdomadaire (`ReplicaReminder`, colonnes `database_connection.replica_checked_at` / `replica_found`, en clair, rien de secret) : une connexion MySQL qui ne lit pas sur réplica et dont le serveur est un Vitess SANS réplica à l'enregistrement ou à la modification (`arm`) est SURVEILLÉE ; à l'ouverture de la connexion, l'interface demande `GET /web/connections/{id}/replica` : si la dernière recherche a 7 jours ou plus, le serveur relit le topo (aucune tâche planifiée) ; un réplica apparu = `{available: true}` et un bandeau (`ReplicaReminder.vue`) propose « Lire sur le réplica » (`POST …/replica/enable` : même vérification, active `readReplica`, met fin à la surveillance, 422 `replica_unavailable` si le réplica a disparu) ou « Plus tard » (`POST …/replica/later` : on n'en reparle que dans une semaine) ; JAMAIS activé d'office (retard de réplication). Un serveur illisible est relu à l'ouverture suivante, un serveur qui n'est plus un Vitess met fin à la surveillance. Avertissement Materialize : un ALTER/DROP/RENAME/TRUNCATE d'une table SOURCE d'un flux VReplication (lue dans `_vt.vreplication` des primaires, `StreamSources`) demande une confirmation `stream_source` dans la console, les scripts de la console et l'aperçu de l'éditeur de structure (`feedsStream`), jamais un refus ; API et MCP inchangés. Diagnostics Vitess : aucun thème à travers vtgate (il envoie chaque lecture du catalogue à UN tablet au hasard, `vitess_tablet_scope`) ; on lit tablet par tablet, `GET .../diagnostics/tablets` puis `?tablet=<alias>` (web, API à jetons et MCP : route et outil `list_tablets`, paramètre `tablet`, droit `diagnostic` ; le serveur construit `keyspace:shard@type|alias` d'après `SHOW VITESS_TABLETS`, jamais d'un texte reçu). Analyse d'un `Engine::Vitess` distinct et découpage en PR : `docs/vitess-tests.md`.
le vérifie, donc une cible ajoutée doit suivre). Pour les ouvrir au réseau : `compose.override.yaml` (ignoré par git, propre à chaque machine) avec
`ports: !override ["8000:8000"]` : sans `!override`, ses ports s'ajoutent à ceux de base au lieu de les remplacer.

## Jeux de données de test (tests/Integration/Support, issue #88)

Principe : SQL pur, déterministe (calculs sur `n`, jamais d'aléatoire), CRÉÉ par le test dans la base des cibles partagées puis supprimé dans un `finally`, jamais par les scripts d'init (qui ne bougent pas : `shop` et ses comptes de lignes restent ceux attendus). Tout objet porte le préfixe `tdb_ds_<8 hex>` (`DatasetSession::name()`) ; `DatasetSession` (DBAL, ou HTTP pour ClickHouse) exécute, lit, décrit, et `leftovers()` liste tables, fonctions et déclencheurs `tdb_ds_*` restants (les tests l'exigent vide après `drop()`). À vérifier à la main après un run : aucun `tdb_*` dans `information_schema` / `pg_proc` des cibles.
`SkewedTable` : grande table de 200 000 lignes (état `done` 60 % / `pending` 35 % / `flagged` 5 %, 365 dates, renvoi vers une table de 2 000 lignes SANS clé étrangère déclarée, car MySQL lui créerait un index, aucun index secondaire), CTE récursive (MySQL `cte_max_recursion_depth`, MariaDB `max_recursive_iterations`), `generate_series`, `numbers()`. Chargement mesuré : 0,3 à 0,7 s par cible relationnelle (ClickHouse 0,03 s), donc aucun groupe `slow` : tout tourne avec `--group integration`. Le test d'usage lit l'EXPLAIN par `PlanService` avant (parcours complet) et après `CREATE INDEX` (lecture par l'index).
`FaultySchema` : une quinzaine de tables fautives. Jugées par l'audit : sans clé primaire (jonction, copie `CREATE TABLE … AS`), UUID en texte comme clé, index en double et index préfixe, somme en flottant, compteur proche de sa limite, et sur PostgreSQL clé étrangère sans index et type à convertir. Sans règle (vues par l'introspection seulement) : clé étrangère sous-entendue par le nom, tables jumelles, collations différentes, MyISAM. Constats mesurés : un `REFERENCES` écrit dans la colonne est CRÉÉ par MySQL 8.4/26 et MariaDB 11.8/13 (plus ignoré), et MySQL/MariaDB refusent de retirer l'index d'une clé étrangère (1553) : « clé sans index » n'existe que sur PostgreSQL. Cas négatif : l'audit du schéma partagé est identique avant et après.
`WorkQueue` (1 000 tâches `todo` indexées sur l'état, 50 produits de 100 unités, colonne `version`) et `ConcurrencyScenariosTest` : un scénario par famille avec `BlockingSession` / `BlockedStatement` (mise à jour perdue et verrou optimiste, `SKIP LOCKED` / `NOWAIT`, interblocage en quatre étapes avec exactement une victime, DDL en attente derrière un SELECT en transaction). `ProgrammableObjects` : déclencheur qui recopie vers une table de synchronisation, fonction déterministe qui rend 2^63-1, 2^63, -2^63-1 et 38 chiffres 9. Pas encore : verrou de plage, tables riches / partitionnées, journal binaire, profil de réplication, `pg_stat_statements`.

## Conventions PHP

`declare(strict_types=1)`, classes `final` (et `readonly` pour les DTO), accolades partout, pas de tableau
associatif nu en retour public, commentaires seulement si le *pourquoi* n'est pas évident.
