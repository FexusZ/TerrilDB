#!/bin/sh
# Accorde l'hôte virtuel nginx EXISTANT sur TERRILDB_PROXY_TIMEOUT (deploy.env), puis recharge nginx (sudo requis).
# À lancer après avoir changé la valeur, ou quand le déploiement signale un désaccord :
#   sh docker/prod/sync-nginx.sh
set -eu

root="$(cd "$(dirname "$0")/../.." && pwd)"
dir="${TERRILDB_DEPLOY_DIR:-$HOME/.config/terrildb}"
[ -r "$dir/deploy.env" ] || { echo "Configuration absente : $dir (lancez d'abord docker/prod/deploy.sh)." >&2; exit 2; }
set -a
. "$dir/deploy.env"
set +a

site="${TERRILDB_NGINX_SITE:-/etc/nginx/sites-available/terrildb}"
seconds="${TERRILDB_PROXY_TIMEOUT:-120}"
[ -e "$site" ] || { echo "$site n'existe pas : lancez d'abord docker/prod/setup-host.sh." >&2; exit 2; }

sudo cp -p "$site" "$site.bak"
sudo sh "$root/docker/prod/proxy-timeout.sh" apply "$site" "$seconds"
if ! sudo nginx -t; then
    echo "nginx refuse la configuration : l'ancienne est remise." >&2
    sudo cp -p "$site.bak" "$site"
    exit 1
fi
sudo systemctl reload nginx
sh "$root/docker/prod/proxy-timeout.sh" check "$site" "$seconds"
echo "L'application relit sa limite au prochain déploiement : relancez docker/prod/deploy.sh si la valeur a changé."
