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

## Exploitation

- Journaux : `docker compose -f compose.prod.yaml logs app` avec `TERRILDB_DEPLOY_DIR` et `TERRILDB_DOMAIN` exportés
  (`set -a; . ~/.config/terrildb/deploy.env; set +a`).
- Sauvegardes : `pg_dump` compressé avant chaque migration (sept conservées). Restauration :
  `gunzip -c <fichier> | docker compose -f compose.prod.yaml exec -T db sh -c 'psql -U "$POSTGRES_USER" "$POSTGRES_DB"'`
  sur une base vide.
- Retour arrière : le script remet l'image précédente si le démarrage échoue, mais ne défait pas les migrations déjà
  passées. Une migration doit donc rester compatible avec la version précédente du code (ajouter avant de retirer).
- Les sessions sont dans un volume : un déploiement ne déconnecte personne.
- Inscription : `APP_REGISTRATION=first_only` (dans `app.env`) ; les autres comptes arrivent par invitation. Le premier compte
  peut se créer en console plutôt que par le formulaire : `php bin/console app:user:create-first <e-mail> <utilisateur>` dans le
  conteneur de l'application, avec un terminal (`docker compose -f compose.prod.yaml exec app …`) ; la commande refuse dès qu'un
  compte existe.
