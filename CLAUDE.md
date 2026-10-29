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
docker compose up php                                     # serveur sur :8000 (GET /api/health)
```

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
