#!/bin/sh
# Déploie TerrilDB sur cette machine : construit l'image, sauvegarde la base, migre, relance et vérifie la santé ;
# en cas d'échec, remet l'image précédente. Les secrets et le domaine vivent HORS du dépôt (TERRILDB_DEPLOY_DIR).
#
#   TERRILDB_DOMAIN=exemple.test sh docker/prod/deploy.sh      # premier lancement : crée la configuration
#   sh docker/prod/deploy.sh                                   # ensuite : relit la configuration
set -eu

root="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$root"

export TERRILDB_DEPLOY_DIR="${TERRILDB_DEPLOY_DIR:-$HOME/.config/terrildb}"
dir="$TERRILDB_DEPLOY_DIR"
mkdir -p "$dir/backups"
chmod 700 "$dir"

secret() { head -c 48 /dev/urandom | base64 | tr -d '/+=\n' | cut -c1-40; }

# Secrets : générés une seule fois, jamais réécrits (changer le mot de passe de la base casserait le volume existant).
if [ ! -e "$dir/db.env" ]; then
    db_password="$(secret)"
    printf 'POSTGRES_DB=terrildb\nPOSTGRES_USER=terrildb\nPOSTGRES_PASSWORD=%s\n' "$db_password" > "$dir/db.env"
    printf 'APP_SECRET=%s\nDATABASE_URL=pdo-pgsql://terrildb:%s@db:5432/terrildb\nAPP_REGISTRATION=first_only\n' \
        "$(secret)" "$db_password" > "$dir/app.env"
    chmod 600 "$dir/db.env" "$dir/app.env"
    echo "Secrets générés dans $dir"
fi

# Réglages propres à l'installation (domaine, port local) : fournis par l'environnement, puis conservés.
if [ -n "${TERRILDB_DOMAIN:-}" ]; then
    printf 'TERRILDB_DOMAIN=%s\nTERRILDB_PORT=%s\n' "$TERRILDB_DOMAIN" "${TERRILDB_PORT:-8090}" > "$dir/deploy.env"
    chmod 600 "$dir/deploy.env"
fi
if [ ! -e "$dir/deploy.env" ]; then
    echo "TERRILDB_DOMAIN manquant : relancez avec TERRILDB_DOMAIN=<domaine> pour le premier déploiement." >&2
    exit 2
fi
set -a
. "$dir/deploy.env"
set +a

tag="$(git rev-parse --short=12 HEAD 2>/dev/null || date +%Y%m%d%H%M%S)"
previous="$(cat "$dir/current-tag" 2>/dev/null || true)"
compose() { docker compose -f compose.prod.yaml "$@"; }

echo "==> Construction de l'image $tag"
TERRILDB_TAG="$tag" compose build app

echo "==> Base interne"
TERRILDB_TAG="$tag" compose up -d --wait db
if [ -n "$previous" ]; then
    backup="$dir/backups/$(date +%Y%m%d-%H%M%S)-$previous.sql.gz"
    TERRILDB_TAG="$tag" compose exec -T db sh -c 'pg_dump -U "$POSTGRES_USER" "$POSTGRES_DB"' | gzip > "$backup"
    chmod 600 "$backup"
    # Sept sauvegardes au plus.
    ls -1t "$dir"/backups/*.sql.gz 2>/dev/null | tail -n +8 | while read -r old; do rm -f "$old"; done
fi

echo "==> Migrations"
TERRILDB_TAG="$tag" compose run --rm --no-deps app php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

echo "==> Démarrage"
if TERRILDB_TAG="$tag" compose up -d --wait --remove-orphans app; then
    echo "$tag" > "$dir/current-tag"
    docker image prune -f >/dev/null
    echo "Déployé : $tag"
    exit 0
fi

echo "Échec du démarrage de $tag :" >&2
TERRILDB_TAG="$tag" compose logs --tail 40 app >&2 || true
if [ -n "$previous" ] && docker image inspect "terrildb:$previous" >/dev/null 2>&1; then
    echo "Retour à $previous (les migrations déjà passées restent appliquées ; sauvegarde dans $dir/backups)." >&2
    TERRILDB_TAG="$previous" compose up -d --wait app || true
fi
exit 1
