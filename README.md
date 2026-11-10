# TerrilDB

Client et gestionnaire de bases de données web, auto-hébergé : entre phpMyAdmin et la console de base de données
d'un IDE. TerrilDB **n'est pas** un moteur de base de données : il se connecte à des bases existantes.

Trois entrées partagent le même cœur : une **interface web**, une **API REST** à jetons et un **serveur MCP**.

## Moteurs pris en charge

| Moteur | Accès | Schéma | Plan d'exécution |
| --- | --- | --- | --- |
| MySQL 8.4 / MariaDB 11.8 | DBAL | oui | oui |
| PostgreSQL 18 | DBAL | oui | oui |
| ClickHouse | interface HTTP | oui (sans clés étrangères) | non |
| Redis | commandes + `SCAN` | navigation par clés | non |

## Fonctions

- Console de requêtes avec garde-fous : une seule instruction, lecture seule par défaut (filtre lexical **et**
  transaction ou réglage imposé par le moteur), délai et nombre de lignes bornés, confirmation avant ce qui détruit.
- Explorateur de schéma, diagramme entité-relation, plan d'exécution et audit d'index (règles locales, rien n'est
  exécuté à votre place).
- Export CSV / JSON, historique des requêtes, journal d'audit.
- Comptes multiples sur invitation, second facteur (TOTP), phrase de récupération facultative.
- **Secrets zero-knowledge** : les identifiants des bases sont chiffrés avec une clé dérivée de votre mot de passe
  (Argon2id, XChaCha20-Poly1305). Le serveur seul ne peut pas les lire.
- API REST (`docs/api.md`) et serveur MCP (`docs/mcp.md`) en lecture seule, par jetons à durée limitée.

## Démarrage

PHP, Composer et Node ne sont pas requis sur la machine : tout passe par Docker.

```bash
sh docker/init-env.sh                                  # mots de passe locaux aléatoires (docker/dev/, ignoré par git)
docker compose up -d db                                # base interne (PostgreSQL)
docker compose run --rm --no-deps php composer install
docker compose run --rm --no-deps node npm install
docker compose run --rm --no-deps node npm run build  # compile l'interface dans public/app/
docker compose run --rm --no-deps php php bin/console doctrine:migrations:migrate
docker compose up php                                  # http://localhost:8000/app/
```

Le premier compte créé administre l'installation. Les suivants arrivent par invitation (`APP_REGISTRATION` règle
l'inscription libre). Le premier compte peut aussi se créer en console, sans ouvrir le formulaire d'inscription (mot de
passe saisi deux fois, sans écho ; refusé dès qu'un compte existe) :

```bash
docker compose run --rm php php bin/console app:user:create-first <adresse-e-mail> <nom-d-utilisateur>
```

Bases cibles de test (MySQL, MariaDB, PostgreSQL, ClickHouse, Redis) :

```bash
docker compose --profile targets up -d --wait
docker compose run --rm --no-deps php vendor/bin/phpunit                    # tests unitaires
docker compose run --rm --no-deps php vendor/bin/phpunit --group integration
```

La liste complète des commandes (qualité, front, migrations) et l'architecture sont dans [`CLAUDE.md`](CLAUDE.md).

## Sécurité

TerrilDB manipule des identifiants de bases de données : ne l'exposez que derrière HTTPS. Renseignez un `APP_SECRET`
propre et `TERRILDB_ALLOWED_HOSTS` en production. Les valeurs de développement du dépôt ne protègent rien.

## Licence

[GNU AGPL-3.0](LICENSE) — Copyright (c) 2026 FexusZ.

Vous pouvez utiliser, étudier et modifier TerrilDB librement, y compris dans une entreprise. Si vous proposez une
version modifiée comme service accessible par le réseau, vous devez en publier les sources sous la même licence.
