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
au premier déploiement et jamais réécrits. Le domaine arrive par la variable GitHub `TERRILDB_DOMAIN` (Settings →
Secrets and variables → Actions → Variables).

## Mise en place (une fois)

1. `gh variable set TERRILDB_DOMAIN --body <domaine>` puis `TERRILDB_DOMAIN=<domaine> sh docker/prod/deploy.sh` :
   premier déploiement, application sur `http://127.0.0.1:8090/app/` seulement.
2. Ouvrir cette adresse et **créer le premier compte** : il administre l'installation. Tant qu'aucun compte n'existe,
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
- le runner a accès à Docker, c'est-à-dire en pratique les droits de son utilisateur sur la machine.

## Exploitation

- Journaux : `docker compose -f compose.prod.yaml logs app` avec `TERRILDB_DEPLOY_DIR` et `TERRILDB_DOMAIN` exportés
  (`set -a; . ~/.config/terrildb/deploy.env; set +a`).
- Sauvegardes : `pg_dump` compressé avant chaque migration (sept conservées). Restauration :
  `gunzip -c <fichier> | docker compose -f compose.prod.yaml exec -T db sh -c 'psql -U "$POSTGRES_USER" "$POSTGRES_DB"'`
  sur une base vide.
- Retour arrière : le script remet l'image précédente si le démarrage échoue, mais ne défait pas les migrations déjà
  passées. Une migration doit donc rester compatible avec la version précédente du code (ajouter avant de retirer).
- Les sessions sont dans un volume : un déploiement ne déconnecte personne.
- Inscription : `APP_REGISTRATION=first_only` (dans `app.env`) ; les autres comptes arrivent par invitation.
