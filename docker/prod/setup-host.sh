#!/bin/sh
# Mise en place UNIQUE sur la machine (droits sudo requis) : hôte virtuel nginx, certificat HTTPS, service du runner.
# À lancer après un premier `docker/prod/deploy.sh` ET la création du premier compte (voir docs/deploy.md) :
#   sh docker/prod/setup-host.sh
set -eu

root="$(cd "$(dirname "$0")/../.." && pwd)"
dir="${TERRILDB_DEPLOY_DIR:-$HOME/.config/terrildb}"
[ -r "$dir/deploy.env" ] || { echo "Lancez d'abord docker/prod/deploy.sh (configuration absente : $dir)." >&2; exit 2; }
# Exporté : compose lit TERRILDB_DOMAIN dans l'environnement (comme deploy.sh).
set -a
. "$dir/deploy.env"
set +a

curl -fsS "http://${TERRILDB_BIND:-127.0.0.1}:$TERRILDB_PORT/api/health" >/dev/null \
    || { echo "L'application ne répond pas sur 127.0.0.1:$TERRILDB_PORT : déployez d'abord." >&2; exit 2; }

# Tant qu'aucun compte n'existe, le premier visiteur deviendrait administrateur : on n'expose rien avant.
accounts="$(cd "$root" && TERRILDB_DEPLOY_DIR="$dir" docker compose -f compose.prod.yaml exec -T db \
    sh -c 'psql -U "$POSTGRES_USER" "$POSTGRES_DB" -tAc "SELECT count(*) FROM app_user"')"
if [ "${accounts:-0}" -lt 1 ]; then
    echo "Aucun compte n'existe encore. Créez le premier compte (administrateur) sur http://127.0.0.1:$TERRILDB_PORT/app/ , puis relancez." >&2
    exit 3
fi

site=/etc/nginx/sites-available/terrildb
if [ ! -e "$site" ]; then
    sed -e "s/__DOMAIN__/$TERRILDB_DOMAIN/g" -e "s/__PORT__/$TERRILDB_PORT/g" "$root/docker/prod/nginx.conf.template" | sudo tee "$site" >/dev/null
    sudo ln -sf "$site" /etc/nginx/sites-enabled/terrildb
else
    echo "$site existe déjà : conservé."
fi
sudo nginx -t
sudo systemctl reload nginx

sudo certbot --nginx -d "$TERRILDB_DOMAIN" --redirect
sudo nginx -t
sudo systemctl reload nginx

# L'application ne doit plus écouter que sur la boucle locale une fois derrière nginx.
if [ "${TERRILDB_BIND:-127.0.0.1}" != "127.0.0.1" ]; then
    sed -i 's/^TERRILDB_BIND=.*/TERRILDB_BIND=127.0.0.1/' "$dir/deploy.env"
    (cd "$root" && TERRILDB_DEPLOY_DIR="$dir" TERRILDB_DOMAIN="$TERRILDB_DOMAIN" TERRILDB_PORT="$TERRILDB_PORT" TERRILDB_BIND=127.0.0.1 \
        TERRILDB_TAG="$(cat "$dir/current-tag")" docker compose -f compose.prod.yaml up -d --no-build app)
fi

sh "$root/docker/prod/install-runner.sh" --service
echo "Terminé : https://$TERRILDB_DOMAIN/app/"
