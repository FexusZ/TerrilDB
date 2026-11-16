# Déploiement sur une machine Docker

Modèle de menaces et limites connues : [`security.md`](security.md).

Chaîne : tests sur les serveurs de GitHub (`.github/workflows/ci.yml`), puis, si tout est vert sur `main`, un runner
GitHub Actions **dédié**, installé sur la machine de production, lance `docker/prod/deploy.sh`.

```
push sur main ─► php / front / integration (GitHub) ─► deploy (runner « terrildb », la machine)
                                                          │ build de l'image → sauvegarde pg_dump → migrations
                                                          └ relance + contrôle de santé (retour à l'image précédente si KO)
navigateur ─► nginx (HTTPS, certbot) ─► 127.0.0.1:8090 ─► conteneur FrankenPHP ─► PostgreSQL (réseau Docker, non publié)
```

## Ce qui n'est PAS dans le dépôt

Tout vit dans `$TERRILDB_DEPLOY_DIR` (défaut `~/.config/terrildb`, droits 700) : `app.env` (APP_SECRET, URL de la base),
`db.env` (mot de passe PostgreSQL), `deploy.env` (domaine, port local) et `backups/`. Les secrets sont tirés au hasard
au premier déploiement et jamais réécrits. Le domaine arrive par le secret GitHub `TERRILDB_DOMAIN` (masqué dans les journaux, publics sur un dépôt public ; Settings →
Secrets and variables → Actions).

## Mise en place (une fois)

1. `printf <domaine> | gh secret set TERRILDB_DOMAIN` puis `TERRILDB_DOMAIN=<domaine> sh docker/prod/deploy.sh` :
   premier déploiement, application sur `http://127.0.0.1:8090/app/` seulement.
2. Ouvrir cette adresse et **créer le premier compte** (depuis une autre machine du réseau : tunnel SSH
   `ssh -L 8090:127.0.0.1:8090 <utilisateur>@<machine>` puis `http://localhost:8090/app/`, ou, en clair sur le réseau
   privé et pour quelques minutes seulement, `TERRILDB_BIND=<adresse privée>` dans `deploy.env` puis
   `docker compose -f compose.prod.yaml up -d app`) : il administre l'installation. Tant qu'aucun compte n'existe,
   le premier visiteur deviendrait administrateur, donc rien n'est exposé avant.
3. `sh docker/prod/setup-host.sh` (sudo) : refuse de continuer sans compte, crée l'hôte virtuel nginx
   (`docker/prod/nginx.conf.template`), obtient le certificat (certbot), installe le service du runner.
4. Les pushes suivants sur `main` déploient seuls.

Le domaine doit déjà pointer vers la machine (DNS) et les ports 80/443 être joignables pour certbot.

## Durée maximale d'une requête : `TERRILDB_PROXY_TIMEOUT`

nginx coupe la réponse d'une requête qui dure plus de `proxy_read_timeout` (504) pendant que la base continue de travailler. Pour
que l'application ne laisse pas un client demander plus que nginx n'attend, **une seule valeur** règle les deux :
`TERRILDB_PROXY_TIMEOUT` (secondes, 120 par défaut, de 30 à 3600) dans `deploy.env`.

- nginx : `proxy_read_timeout` et l'en-tête `X-TerrilDB-Proxy-Timeout` sont rendus depuis cette valeur (`nginx.conf.template`).
- application : `compose.prod.yaml` la lui passe ; la durée qu'un client peut demander (`timeoutMs` de la console, des scripts, de
  l'API et du MCP) est cette valeur moins 20 s (100 s par défaut). Au-dessus : refus 422 `invalid_input`, jamais un 504 muet.
- Pour la changer : modifier `TERRILDB_PROXY_TIMEOUT` dans `deploy.env`, lancer `sh docker/prod/sync-nginx.sh` (sudo : aligne
  l'hôte virtuel existant, teste la configuration, recharge nginx, remet l'ancienne si nginx la refuse), puis redéployer.
- Contrôle d'accord, à deux niveaux : `deploy.sh` compare la valeur à celle de l'hôte virtuel (`proxy-timeout.sh check`) et AVERTIT
  (sans faire échouer un déploiement qu'un retour arrière ne réparerait pas) ; à l'exécution, l'application relit l'en-tête que nginx
  ajoute à chaque requête et écrit une erreur dans son journal si les deux valeurs diffèrent (une fois par heure).
- Un site nginx créé avant cette valeur garde son ancien réglage et n'envoie pas l'en-tête : lancer `sh docker/prod/sync-nginx.sh`
  une fois (il ajoute l'en-tête et accorde le délai).

## Sécurité du runner (dépôt public)

Un runner auto-hébergé exécute le code d'un workflow sur la machine. Garde-fous :
- le job `deploy` ne tourne que sur un `push` vers `main` ; il n'existe pas pour une pull request ;
- les workflows des PR de contributeurs externes demandent une **approbation manuelle** (réglage du dépôt « Require
  approval for all external contributors », à ne pas relâcher). **Ne jamais approuver** la PR d'un inconnu qui modifie
  `.github/` ou `docker/` : un workflow modifié pourrait cibler le runner ;
- le jeton du workflow est en lecture seule, le runner n'est enregistré que sur ce dépôt ;
- les journaux d'un dépôt public sont publics : le job de déploiement y affiche le nom de la machine et le chemin du
  runner (« Set up job », « Checkout »). Le domaine est un secret, donc masqué ; ne jamais `echo` autre chose dans le script ;
- le runner a accès à Docker, c'est-à-dire en pratique les droits de son utilisateur sur la machine.

## Limiter ce que le serveur peut joindre

TerrilDB ouvre des connexions vers les bases que les utilisateurs lui indiquent. Le logiciel réserve déjà aux administrateurs
la boucle locale, les liens locaux et la base interne (`TargetHostPolicy`), mais un filtre applicatif n'est pas un pare-feu :
la limite solide se pose sur la machine.

**Base interne isolée (déjà en place).** `compose.prod.yaml` met PostgreSQL sur un réseau Docker `backend` déclaré `internal` : il
n'est partagé qu'avec l'application, n'est pas publié, et la base ne peut elle-même joindre aucune adresse extérieure. L'application
est en plus sur le réseau `default`, par lequel elle joint les bases des utilisateurs.

**Règles de sortie du conteneur (à poser une fois, sur l'hôte, avec sudo).** Elles ne sont pas appliquées par `deploy.sh` : elles
dépendent des bases que vous connectez, et un réglage de pare-feu qui se trompe coupe l'application. Principe : tout ce qui sort du
réseau `terrildb-prod_default` est refusé, sauf les réponses aux connexions déjà ouvertes, le DNS, et les bases cibles listées.

```bash
SUBNET=$(docker network inspect terrildb-prod_default --format '{{(index .IPAM.Config 0).Subnet}}')

sudo iptables -N TERRILDB-EGRESS
sudo iptables -A TERRILDB-EGRESS -m conntrack --ctstate ESTABLISHED,RELATED -j RETURN
sudo iptables -A TERRILDB-EGRESS -p udp --dport 53 -j RETURN                       # DNS
sudo iptables -A TERRILDB-EGRESS -d <adresse de la base> -p tcp --dport <port> -j RETURN   # une ligne par base cible
sudo iptables -A TERRILDB-EGRESS -j DROP
sudo iptables -I DOCKER-USER -s "$SUBNET" -j TERRILDB-EGRESS
```

À savoir avant de les poser :
- `DOCKER-USER` est la chaîne que Docker réserve à l'administrateur pour filtrer le trafic des conteneurs ; les règles y survivent au redémarrage de Docker, pas à celui de la machine (`netfilter-persistent` ou équivalent pour les garder).
- Chaque nouvelle base cible demande une ligne de plus : c'est le prix de la limite. Les adresses IPv6 relèvent de `ip6tables`.
- Le trafic du conteneur vers la machine elle-même ne passe pas par `DOCKER-USER` mais par la chaîne `INPUT` : pour fermer aussi cette voie, filtrer `INPUT` pour `-s "$SUBNET"` (laisser passer le DNS et les réponses établies).
- Après la pose, vérifier depuis le conteneur qu'une adresse non listée est bien injoignable (`docker compose -f compose.prod.yaml exec app php -r 'var_dump(@fsockopen("1.1.1.1", 443, $e, $m, 3));'` doit rendre `false`) et qu'une base listée l'est toujours, avant de fermer la session.
- Ces règles ne sont pas testées par l'intégration continue : à valider sur votre machine.

## Durcissement du conteneur applicatif

- **Système de fichiers en lecture seule** (`read_only: true` dans `compose.prod.yaml`). L'application n'écrit que dans quatre endroits :
  les `tmpfs` `/app/var/cache` (cache Symfony réchauffé au démarrage, compteurs de limitation de tentatives, sessions du protocole MCP),
  `/app/var/log` et `/tmp` (état de Caddy), et le volume `sessions` (`/data/sessions`, survit aux redéploiements). Tout le reste, code
  compris, est refusé en écriture : un fichier déposé par une faille ne peut plus s'installer dans l'image.
- **Hôtes de confiance** : `docker/prod/entrypoint.sh` pose `SYMFONY_TRUSTED_HOSTS` à partir de `TERRILDB_ALLOWED_HOSTS` (donc du domaine
  de `deploy.env`, jamais écrit dans le dépôt), plus `localhost` et `127.0.0.1` pour le contrôle de santé. Une requête dont l'en-tête
  `Host` n'est pas listé reçoit 400, avant tout contrôleur (nginx doit donc transmettre le `Host` d'origine, ce que fait
  `nginx.conf.template`).
- **Risques** : les compteurs de tentatives et les sessions MCP, en `tmpfs`, repartent de zéro à chaque redémarrage du conteneur (les
  sessions de l'interface, elles, restent dans le volume). Si un futur composant écrit ailleurs, il échoue avec « Read-only file system »
  dans `docker compose -f compose.prod.yaml logs app` : ajouter alors un `tmpfs` ou un volume, ne pas retirer `read_only`.
- **Retour arrière** : retirer `read_only: true` (et au besoin les `tmpfs`) de `compose.prod.yaml`, puis `docker compose -f compose.prod.yaml up -d app` ;
  pour les seuls hôtes, définir `SYMFONY_TRUSTED_HOSTS` dans `app.env` à un motif plus large n'a pas d'effet (l'entrypoint le remplace) :
  revenir au commit précédent de `docker/prod/entrypoint.sh`. `deploy.sh` remet de lui-même l'image précédente si le démarrage échoue.
- **Vérifié hors production** sur un projet Compose jetable (image construite depuis la branche, domaine factice, base jetable) : démarrage
  et santé, création du premier compte, connexion, requête et plan, `docker diff` vide, aucune erreur d'écriture, 400 pour un `Host`
  inconnu, session conservée après redémarrage. Reste à constater en production : le comportement derrière le nginx réel (en-tête `Host`
  transmis, HTTPS) et au premier déploiement après fusion.

- **Durée des sessions** : voir « Sessions » plus bas.

## Variables d'environnement de production

Trois fichiers dans `$TERRILDB_DEPLOY_DIR` (droits 600) alimentent `compose.prod.yaml` ; un défaut est la valeur du code
(`config/services.yaml`, `.env`) quand rien ne la pose. Un changement de `app.env`, `db.env` ou `deploy.env` ne s'applique qu'au
redémarrage du conteneur concerné (`docker compose -f compose.prod.yaml up -d app`, ou un déploiement).

| Variable | Défaut | Rôle | Où la poser |
| --- | --- | --- | --- |
| `APP_SECRET` | tirée au hasard au premier déploiement | secret du noyau Symfony (voir « Rotation des secrets ») | `app.env` |
| `DATABASE_URL` | `pdo-pgsql://terrildb:<mot de passe>@db:5432/terrildb` (généré) | base interne ; son hôte sert aussi à `TargetHostPolicy` (un membre ne peut pas la viser) | `app.env` |
| `APP_REGISTRATION` | `first_only` | `first_only` : inscription libre seulement tant qu'aucun compte n'existe ; `open` : toujours ouverte | `app.env` |
| `TERRILDB_SESSION_IDLE_SECONDS` | `0` (pas de limite) | inactivité maximale d'une session | `app.env` |
| `TERRILDB_SESSION_MAX_SECONDS` | `0` (pas de limite) | durée maximale depuis la connexion, même en restant actif | `app.env` |
| `TERRILDB_AUDIT_RETENTION_DAYS` | `365` | ancienneté au-delà de laquelle `app:audit:purge` supprime | `app.env` |
| `TERRILDB_BIG_TABLE_WARN_ROWS` | `100000` | lignes estimées à partir desquelles modifier ou supprimer une table avertit (`0` : désactivé) | `app.env` |
| `TERRILDB_BIG_TABLE_CONFIRM_ROWS` | `1000000` | seuil au-delà duquel il faut retaper le nom de la table (`0` : désactivé) | `app.env` |
| `TERRILDB_MAIL_FROM` | vide | expéditeur des invitations ; l'envoi n'est actif que si `MAILER_DSN` est aussi renseigné (`TERRILDB_PUBLIC_URL` est toujours posée par compose) | `app.env` |
| `MAILER_DSN` | `null://null` (rien n'est envoyé) | transport `symfony/mailer` | `app.env` |
| `TERRILDB_DOMAIN` | aucun (obligatoire) | nom public ; secret GitHub `TERRILDB_DOMAIN`, réécrit dans `deploy.env` à chaque déploiement ; compose en tire `TERRILDB_ALLOWED_HOSTS` et `TERRILDB_PUBLIC_URL` | secret GitHub, puis `deploy.env` |
| `TERRILDB_PORT` | `8090` | port local publié (nginx y pointe) | `deploy.env` (réécrit à chaque déploiement, voir ci-dessous) |
| `TERRILDB_BIND` | `127.0.0.1` | adresse d'écoute ; autre chose que la boucle locale = quelques minutes, pour créer le premier compte | `deploy.env` |
| `TERRILDB_PROXY_TIMEOUT` | `120` (30 à 3600) | délai de nginx ET limite de durée d'une requête (moins 20 s) ; voir plus haut | `deploy.env` |
| `POSTGRES_DB`, `POSTGRES_USER`, `POSTGRES_PASSWORD` | `terrildb`, `terrildb`, tirée au hasard | initialisation de la base interne (le mot de passe ne sert qu'à la CRÉATION du volume) | `db.env` |

`deploy.sh` réécrit `deploy.env` à CHAQUE déploiement (le secret `TERRILDB_DOMAIN` est toujours fourni) : seul `TERRILDB_PROXY_TIMEOUT`
est conservé, `TERRILDB_PORT` et `TERRILDB_BIND` reviennent à `8090` et `127.0.0.1` sauf s'ils figurent dans l'environnement du runner. Un
réglage ajouté à la main dans `deploy.env` (autre ligne) est donc perdu au déploiement suivant.

Posées par `compose.prod.yaml` ou l'image : ne pas les mettre dans `app.env` (la section `environment:` de compose l'emporte sur
`env_file`) : `APP_ENV=prod`, `TERRILDB_ALLOWED_HOSTS` (= le domaine ; noms acceptés par le point d'entrée MCP, séparés par des virgules ;
défaut du code `localhost,127.0.0.1,[::1]`), `TERRILDB_PUBLIC_URL` (= `https://<domaine>`, base des liens d'invitation, jamais l'en-tête
`Host`), `SYMFONY_TRUSTED_PROXIES=private_ranges` (adresse cliente lue dans `X-Forwarded-For` du nginx de l'hôte, qui arrive d'un réseau privé),
`SYMFONY_TRUSTED_HOSTS` (calculée par `entrypoint.sh` : les noms de `TERRILDB_ALLOWED_HOSTS`, `localhost` et `127.0.0.1`).
`DEFAULT_URI` (`http://localhost`, `.env`) ne sert qu'aux URL générées en console : sans effet en production.

Variables de l'environnement du shell qui lance les scripts : `TERRILDB_DEPLOY_DIR` (défaut `~/.config/terrildb`), `TERRILDB_TAG` (étiquette
d'image, posée par `deploy.sh`), `TERRILDB_NGINX_SITE` (défaut `/etc/nginx/sites-available/terrildb`), `TERRILDB_RUNNER_DIR` (défaut
`~/actions-runner-terrildb`).

## Sessions

`TERRILDB_SESSION_IDLE_SECONDS` et `TERRILDB_SESSION_MAX_SECONDS` (`app.env`) : `0` ou absent = aucune limite. Passé l'une des deux, la
session est invalidée au prochain appel : il faut se reconnecter (le second facteur est redemandé). La clé de chiffrement des connexions
ne vit que le temps de la session : elle n'est pas dans la base, et un redémarrage du conteneur ne la perd pas, mais la session
invalidée l'efface. Exemple : 1800 et 43200. Les sessions sont des fichiers du volume `sessions` (un déploiement ne déconnecte personne) ;
le cookie `TERRILDB_SESSION` est `HttpOnly`, `SameSite=Lax`, `Secure` quand la requête arrive en HTTPS ; un second cookie,
`TERRILDB_UNLOCK`, porte le secret qui déverrouille la clé gardée enveloppée côté serveur : le volume seul ne suffit pas à la retrouver.
Les jetons d'API ne dépendent pas de la session.

## Sauvegarde et restauration de la base interne

**Ce que fait `deploy.sh`** : à chaque déploiement qui suit le premier, avant les migrations, `pg_dump` (SQL texte) compressé par `gzip`
dans `$TERRILDB_DEPLOY_DIR/backups/<AAAAMMJJ-HHMMSS>-<étiquette de l'image précédente>.sql.gz` (droits 600, dossier 700), les **sept plus
récents** sont gardés (les autres supprimés au même moment). Il n'y a donc : pas de sauvegarde au premier déploiement, pas de sauvegarde
périodique (un jour sans déploiement n'en produit aucune), et tout reste sur la machine.

**Ce qu'elle contient** : toute la base interne : comptes (hash Argon2id du mot de passe, paramètres de dérivation, secret TOTP et codes de
récupération chiffrés), connexions aux bases cibles **chiffrées** (jamais en clair), jetons d'API (empreinte du secret et enveloppe de la clé),
invitations, historique des requêtes, journal d'audit, versions de migrations. **Ce qu'elle ne contient pas** : aucune clé de déchiffrement
(dérivée du mot de passe de chaque utilisateur, jamais stockée : une sauvegarde volée ne livre pas les identifiants des bases), ni `app.env`,
`db.env`, `deploy.env` (à sauvegarder à part, droits 600), ni le volume `sessions`. Elle contient en revanche le texte des requêtes de
l'historique et du journal : traitez-la comme sensible, chiffrez toute copie qui quitte la machine (par exemple `age` ou `gpg`).

**Copie hors machine** (à planifier, non fournie) : copier régulièrement le dossier `backups/` vers un autre support, chiffré. Pour une
sauvegarde du jour, hors déploiement, avec le même format :

```bash
cd <dossier-du-depot>; set -a; . ~/.config/terrildb/deploy.env; set +a; export TERRILDB_DEPLOY_DIR=~/.config/terrildb
B=~/.config/terrildb/backups/manuelle-$(date +%Y%m%d-%H%M%S).sql
umask 077
docker compose -f compose.prod.yaml exec -T db sh -c 'pg_dump -U "$POSTGRES_USER" "$POSTGRES_DB"' > "$B" && [ -s "$B" ] && gzip "$B" || { rm -f "$B" "$B.gz"; echo "sauvegarde en échec" >&2; }
```
(Le vidage va dans un fichier, puis `gzip` : dans un tube `pg_dump | gzip`, un `pg_dump` en échec laisserait un fichier presque vide, compté parmi les sept.)
(`deploy.sh` ne supprime que les fichiers `*.sql.gz` au-delà de sept : une copie manuelle placée dans `backups/` entre dans la rotation ;
la ranger ailleurs pour la garder.)

**Tester une restauration** (sans toucher à la production, base jetable dans le même serveur PostgreSQL) :

```bash
F=~/.config/terrildb/backups/<fichier>.sql.gz
C="docker compose -f compose.prod.yaml exec -T db"
$C sh -c 'createdb -U "$POSTGRES_USER" terrildb_essai'
gunzip -c "$F" | $C sh -c 'psql -v ON_ERROR_STOP=1 -q -U "$POSTGRES_USER" terrildb_essai'
$C sh -c 'psql -U "$POSTGRES_USER" terrildb_essai -tAc "SELECT count(*) FROM app_user"'
$C sh -c 'dropdb -U "$POSTGRES_USER" terrildb_essai'
```

**Restaurer pour de bon** (la base d'abord remise à vide ; la restauration remplace tout ce qui s'est écrit depuis la sauvegarde) :

1. Arrêter l'application : `docker compose -f compose.prod.yaml stop app`.
2. Au besoin, garder l'état actuel : un `pg_dump` comme ci-dessus.
3. Vérifier d'abord que la sauvegarde est lisible (`gunzip -t <fichier> && echo ok`) et, de préférence, l'avoir essayée sur une base jetable (ci-dessus) : la
   commande suivante détruit la base actuelle, et rien ne la remplace si le fichier est inutilisable. `docker compose -f compose.prod.yaml exec -T db sh -c 'dropdb -U "$POSTGRES_USER" "$POSTGRES_DB" && createdb -U "$POSTGRES_USER" "$POSTGRES_DB"'`
4. `gunzip -c <fichier> | docker compose -f compose.prod.yaml exec -T db sh -c 'psql -v ON_ERROR_STOP=1 -q -U "$POSTGRES_USER" "$POSTGRES_DB"'`
5. Redémarrer : `docker compose -f compose.prod.yaml up -d app` (avec l'étiquette `TERRILDB_TAG` voulue : celle de `current-tag`). Si la sauvegarde
   précède des migrations, un déploiement (ou `php bin/console doctrine:migrations:migrate --no-interaction` dans le conteneur) les rejoue.
6. Vérifier : se connecter, ouvrir une connexion enregistrée.

Les connexions restaurées se relisent avec le mot de passe que le compte avait AU MOMENT de la sauvegarde : un mot de passe changé depuis
ne déchiffre pas la copie (l'ancien, si). Un compte dont on a perdu le mot de passe n'a pas de récupération côté serveur (sauf phrase de récupération générée).

## Rotation des secrets

Ordre sûr : préparer, appliquer, vérifier ; toujours après une sauvegarde fraîche (ci-dessus).

- **`APP_SECRET`** (`app.env`). Aucun jeton de TerrilDB n'en dérive : sessions (fichiers du volume), jetons d'API, jetons de confirmation,
  invitations et codes de récupération sont stockés par empreinte SHA-256 ou Argon2id, la clé de chiffrement vient du mot de passe de
  l'utilisateur. L'application n'utilise ni jeton CSRF Symfony (c'est l'en-tête `X-TerrilDB-Request`) ni « se souvenir de moi » ni lien signé. Remplacer
  la valeur (40 caractères alphanumériques, par exemple `head -c 48 /dev/urandom | base64 | tr -d '/+=\n' | cut -c1-40`), puis
  `docker compose -f compose.prod.yaml up -d app` ou un déploiement. Effet attendu : rien de visible ; vérifier une connexion.
- **Mot de passe de la base interne.** `POSTGRES_PASSWORD` n'est lu qu'à la création du volume : modifier `db.env` seul ne change rien. Dans
  l'ordre : (1) `printf "ALTER ROLE terrildb PASSWORD '<nouveau>';\n" | docker compose -f compose.prod.yaml exec -T db sh -c 'psql -U "$POSTGRES_USER" "$POSTGRES_DB"'`
  (la liaison locale du conteneur n'exige pas l'ancien) ; (2) le même mot de passe dans `db.env` ET dans `DATABASE_URL` de `app.env` (un
  caractère spécial s'encode en pourcentage dans l'URL ; les mots de passe générés n'en ont pas) ; (3) `up -d --force-recreate app`.
  Entre (1) et (3) l'application ne joint plus la base : l'enchaîner vite.
- **Secrets du déploiement** : le domaine (secret GitHub `TERRILDB_DOMAIN`) se change dans GitHub puis dans l'hôte virtuel nginx et le certificat
  (`setup-host.sh` ne refait pas un site existant : adapter le fichier ou le supprimer avant). Le jeton d'enregistrement du runner
  (`install-runner.sh`) n'est jamais conservé : il expire en une heure ; pour remplacer le runner, le supprimer dans les réglages du dépôt et
  relancer le script. Aucun secret d'API GitHub n'est stocké sur la machine par TerrilDB.
- **Mots de passe des utilisateurs, TOTP, jetons d'API** : propres à chacun. Changer son mot de passe rechiffre ses connexions et révoque ses
  jetons d'API ; une phrase de récupération est supprimée par ce changement.
- **Clé de relais ou de tunnel** : il n'y en a pas côté serveur. Le saut par bastion SSH existe dans le modèle de données (`SshJump`, clé privée
  dans les paramètres chiffrés de la connexion, donc propre à l'utilisateur) mais `TargetOpener` ne l'ouvre pas encore (opération non prise
  en charge) : rien à faire tourner.

## TLS vers les bases cibles

Ce que fait TerrilDB aujourd'hui : il ne pose **aucune** option TLS pour MySQL/MariaDB et PostgreSQL (`pdo_mysql`, `pdo_pgsql` : valeurs
par défaut du pilote, connexion à l'adresse épinglée, aucune vérification de nom ni d'autorité) ; ClickHouse parle HTTPS sur les ports 8443 et 443
(HTTP clair sur tout autre port ; le client HTTP vérifie le certificat d'après le nom saisi) ; Redis parle TLS sur le port 6380 avec vérification de l'autorité
et du nom (`peer_name`). Il n'y a pas encore de réglage par connexion (mode, autorité) : c'est la suite de l'issue #45. En attendant, l'état
réel d'une connexion se lit (« chiffrement » : `GET /web/connections/{id}/diagnostics/encryption`, MySQL/MariaDB et PostgreSQL) et **le serveur cible
doit l'imposer** plutôt que de compter sur le client :

- MySQL : `require_secure_transport=ON` (ou `REQUIRE SSL` sur le compte) ; MariaDB : `REQUIRE SSL` sur le compte, `require_secure_transport` aussi.
- PostgreSQL : des lignes `hostssl` dans `pg_hba.conf` (et plus de `host` pour les comptes concernés), `ssl = on`.
- ClickHouse : exposer seulement le port HTTPS (8443). Redis : port TLS seulement (`tls-port`, `port 0`).
- Entre TerrilDB et une base distante sans TLS, passer par un réseau privé ou un VPN : un mot de passe de base circule sinon en clair.

L'absence de vérification de nom signifie qu'un chiffrement activé protège contre l'écoute, pas contre un serveur usurpé.

## Mise à jour et retour arrière

- Mise à jour = un push sur `main` (voir plus haut) ou `sh docker/prod/deploy.sh` à la main : construit l'image `terrildb:<12 caractères du commit>`,
  sauvegarde, migre, relance, vérifie `GET /api/health` (conteneur et `docker compose --wait`).
- Retour arrière **automatique** : seulement si la nouvelle image ne devient pas saine. Il relance `terrildb:<précédente>` ; il ne défait
  **aucune migration** et ne restaure **pas** la sauvegarde. Une migration doit donc rester compatible avec le code précédent (ajouter avant de
  retirer). Si un échec survient pendant les migrations, le script s'arrête sans relancer l'ancienne image : l'application tourne toujours sur l'ancien
  conteneur (non recréé) mais un schéma partiellement migré est possible : relire les journaux de la migration, au besoin restaurer.
- Retour arrière **manuel** vers un déploiement sain plus ancien : `TERRILDB_TAG=<étiquette> docker compose -f compose.prod.yaml up -d --no-build app`
  (les images `terrildb:<étiquette>` précédentes restent sur la machine tant qu'on ne les supprime pas), puis écrire l'étiquette dans
  `$TERRILDB_DEPLOY_DIR/current-tag`. Si une migration irréversible est passée, restaurer aussi la sauvegarde correspondante (étiquette dans son nom).
- Un `main` redéployé par erreur : revenir par un nouveau commit (jamais de push forcé sur `main`).

## Surveillance

- Santé : `GET /api/health` (utilisé par le contrôle du conteneur, toutes les 5 s) ; `docker compose -f compose.prod.yaml ps` indique `healthy`.
- Journaux : `docker compose -f compose.prod.yaml logs --tail 200 app` (PHP écrit sur `stderr`, `log_errors` actif, erreurs non affichées au client ; une
  erreur par heure si nginx et l'application ne sont pas d'accord sur le délai). Le journal d'audit n'est pas dans ces journaux : il est en base.
- Journal d'audit (`audit_event`, lisible par chaque compte pour ses propres actions, `/security/activity`) : connexions (`login.success`, `login.failure`,
  `login.throttled`), comptes, mots de passe, second facteur, phrase de récupération, invitations, jetons (`token.created|refreshed|revoked`), connexions
  de bases (`connection.created|updated|deleted`, `target.forbidden`), requêtes (`query.executed|failed|refused|prepared|linted`), modifications de structure
  et de données (`schema.changed|failed`, `data.changed|failed`) et lectures de diagnostic (`diagnostic.read`). Il ne contient jamais de mot de passe,
  de jeton ni de valeur de colonne d'une modification de données ; il contient le texte des instructions saisies par l'utilisateur.
- Purge : `app:audit:purge` (voir « Exploitation ») ; rien ne s'efface sans elle.
- À surveiller : `login.throttled` répétés (bannissements d'adresses, `app:ban:lift`), `target.forbidden` (quelqu'un vise la machine elle-même), espace
  disque du volume `db-data` et du dossier `backups/`.

## Exploitation

- Journaux : `docker compose -f compose.prod.yaml logs app` avec `TERRILDB_DEPLOY_DIR` et `TERRILDB_DOMAIN` exportés
  (`set -a; . ~/.config/terrildb/deploy.env; set +a`).
- Sauvegarde, restauration, retour arrière : sections ci-dessus.
- Les sessions sont dans un volume : un déploiement ne déconnecte personne.
- Inscription : `APP_REGISTRATION=first_only` (dans `app.env`) ; les autres comptes arrivent par invitation. Le premier compte
  peut se créer en console plutôt que par le formulaire : `php bin/console app:user:create-first <e-mail> <utilisateur>` dans le
  conteneur de l'application, avec un terminal (`docker compose -f compose.prod.yaml exec app …`) ; la commande refuse dès qu'un
  compte existe.
- Journal d'audit : rien ne le purge tout seul. Les événements plus vieux que `TERRILDB_AUDIT_RETENTION_DAYS` (365 par
  défaut, dans `app.env`) se suppriment avec `app:audit:purge`. À planifier une fois par jour sur la machine hôte, par
  exemple avec `cron` (`crontab -e`, sans sudo si l'utilisateur peut lancer Docker) :
  `17 4 * * * cd <dossier-du-depot> && docker compose -f compose.prod.yaml exec -T app php bin/console app:audit:purge`.
  Vérifier à la main une première fois (la commande affiche le nombre d'événements supprimés).
- Grosses tables : modifier la structure (ou supprimer) une table dont le nombre de lignes ESTIMÉ par le moteur (jamais un
  COUNT) atteint `TERRILDB_BIG_TABLE_WARN_ROWS` (100000 par défaut) affiche un avertissement ; à partir de
  `TERRILDB_BIG_TABLE_CONFIRM_ROWS` (1000000 par défaut) il faut en plus retaper le nom de la table. À régler dans `app.env`,
  par installation ; `0` désactive le seuil. Si le moteur ne donne aucune estimation, l'interface le dit sans avertir.
- Bannissement d'adresse après des échecs de connexion répétés : `php bin/console app:ban:lift <adresse>` dans le conteneur
  le lève (par exemple pour un réseau partagé). L'état des blocages vit dans le cache de l'application : un vidage du cache
  ou un déploiement qui le vide lève aussi les blocages en cours.

## Pour aller plus loin

[`security.md`](security.md) (modèle de menaces), [`api.md`](api.md), [`mcp.md`](mcp.md), [README](../README.md).
