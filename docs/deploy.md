# Déploiement sur une machine Docker

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
`db.env` (mot de passe PostgreSQL), `deploy.env` (domaine, port local) et `backups/`. `deploy.env` n'est jamais écrasé : à chaque déploiement seul `TERRILDB_DOMAIN` est mis à jour (écriture atomique, droits inchangés), `TERRILDB_PORT`, `TERRILDB_BIND`, `TERRILDB_PROXY_TIMEOUT` et toute autre ligne `NOM=valeur` ajoutée à la main sont conservés (l'environnement ne fournit une valeur que si elle manque au fichier). Les secrets sont tirés au hasard
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

- **Durée des sessions** (facultatif, dans `app.env`) : `TERRILDB_SESSION_IDLE_SECONDS` (inactivité) et `TERRILDB_SESSION_MAX_SECONDS`
  (durée maximale depuis la connexion, même si l'on reste actif). `0` ou absent = pas de limite, comme avant. Passé la limite, la session
  est invalidée (et avec elle la clé de chiffrement qu'elle portait) : il faut se reconnecter. Par exemple 1800 et 43200.

## Reste de l'issue de durcissement (non traité ici)

- **TLS vers les bases cibles** : les pilotes PDO ne vérifient aucun nom de certificat aujourd'hui ; une option par connexion (mode,
  autorité) est un chantier à part.
- **Rotation des secrets** (`APP_SECRET`, mot de passe de la base interne) : manuelle. `APP_SECRET` invalide les sessions et les jetons de
  protection ; le mot de passe PostgreSQL se change dans la base ET dans `db.env`/`app.env`, puis redémarrage.
- **Sauvegardes** : `pg_dump` avant migration seulement (sept conservées, sur la même machine). Une copie hors machine, chiffrée, reste à planifier.

## Exploitation

- Journaux : `docker compose -f compose.prod.yaml logs app` avec `TERRILDB_DEPLOY_DIR` et `TERRILDB_DOMAIN` exportés
  (`set -a; . ~/.config/terrildb/deploy.env; set +a`).
- Sauvegardes : `pg_dump` compressé avant chaque migration (sept conservées, créées en 600). Un `pg_dump` en échec ou vide arrête le déploiement AVANT les migrations, supprime le fichier partiel et ne purge rien. Restauration :
  `gunzip -c <fichier> | docker compose -f compose.prod.yaml exec -T db sh -c 'psql -U "$POSTGRES_USER" "$POSTGRES_DB"'`
  sur une base vide.
- Retour arrière : le script remet l'image précédente si le démarrage échoue, mais ne défait pas les migrations déjà
  passées. Une migration doit donc rester compatible avec la version précédente du code (ajouter avant de retirer).
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
- Masquage des textes montrés par les diagnostics (erreur de réplication aujourd'hui) : par défaut les secrets (mot de passe, jeton)
  et l'infrastructure (compte, hôte, adresse de la source) y sont masqués. `TERRILDB_MASKING=0` (dans `app.env`, par installation)
  coupe tout masquage : le texte est rendu tel que le moteur le dit. Une valeur de ligne (`Duplicate entry '…'`) n'est jamais
  masquée ; donner à la connexion un compte aux droits adaptés relève de la base cible.
- Bannissement d'adresse après des échecs de connexion répétés : `php bin/console app:ban:lift <adresse>` dans le conteneur
  le lève (par exemple pour un réseau partagé). L'état des blocages vit dans le cache de l'application : un vidage du cache
  ou un déploiement qui le vide lève aussi les blocages en cours.
