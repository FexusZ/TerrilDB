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

# Restes d'un déploiement interrompu (fichier temporaire de deploy.env, sauvegarde commencée) : ceux de plus d'un jour ne
# peuvent plus appartenir à un déploiement en cours. Seuls ces noms-là sont touchés, jamais une sauvegarde `*.sql.gz`.
find "$dir" -maxdepth 1 -type f -name '.deploy.env.*' -mtime +0 -delete 2>/dev/null || true
find "$dir/backups" -maxdepth 1 -type f \( -name '.partial-*' -o -name '*.part' \) -mtime +0 -delete 2>/dev/null || true

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
    # Le domaine finit dans un fichier lu par le shell : un seul mot de caractères de nom d'hôte, jamais de saut de ligne ;
    # et une forme de nom d'hôte : ni point ni tiret en tête ou en queue, ni points de suite, ni étiquette qui commence ou finit
    # par un tiret.
    case "$TERRILDB_DOMAIN" in
        *[!A-Za-z0-9.-]*|.*|-*|*.|*-|*..*|*.-*|*-.*)
            echo "TERRILDB_DOMAIN invalide : un nom d'hôte (lettres, chiffres, points et tirets, sans point ni tiret en bout d'étiquette)." >&2
            exit 2
            ;;
    esac
    # Le fichier existant est relu, pas remplacé : seul le domaine change, port, adresse, délai du proxy et toute autre ligne
    # NOM=valeur ajoutée à la main sont conservés ; ce que l'environnement fournit ne sert qu'à l'absence d'une valeur.
    env_file="$dir/deploy.env"
    kept() { sed -n "s/^$1=//p" "$env_file" 2>/dev/null | head -n 1 || true; }
    tmp_env="$(mktemp "$dir/.deploy.env.XXXXXX")"
    trap 'rm -f "$tmp_env"' EXIT
    port="$(kept TERRILDB_PORT)"
    bind="$(kept TERRILDB_BIND)"
    timeout="$(kept TERRILDB_PROXY_TIMEOUT)"
    {
        printf 'TERRILDB_DOMAIN=%s\nTERRILDB_PORT=%s\nTERRILDB_BIND=%s\nTERRILDB_PROXY_TIMEOUT=%s\n' \
            "$TERRILDB_DOMAIN" "${port:-${TERRILDB_PORT:-8090}}" "${bind:-${TERRILDB_BIND:-127.0.0.1}}" "${timeout:-${TERRILDB_PROXY_TIMEOUT:-120}}"
        if [ -e "$env_file" ]; then
            grep -v -e '^TERRILDB_DOMAIN=' -e '^TERRILDB_PORT=' -e '^TERRILDB_BIND=' -e '^TERRILDB_PROXY_TIMEOUT=' "$env_file" || true
        fi
    } > "$tmp_env"
    chmod "$(stat -c %a "$env_file" 2>/dev/null || echo 600)" "$tmp_env"
    mv -f "$tmp_env" "$env_file"
    trap - EXIT
fi
if [ ! -e "$dir/deploy.env" ]; then
    echo "TERRILDB_DOMAIN manquant : relancez avec TERRILDB_DOMAIN=<domaine> pour le premier déploiement." >&2
    exit 2
fi
# Les bastions du tunnel ne viennent QUE de deploy.env : une variable du shell du runner ne doit pas allumer le tunnel.
unset TERRILDB_SSH_ALLOWED_BASTIONS
set -a
. "$dir/deploy.env"
set +a

tag="$(git rev-parse --short=12 HEAD 2>/dev/null || date +%Y%m%d%H%M%S)"
previous="$(cat "$dir/current-tag" 2>/dev/null || true)"
compose() { docker compose -f compose.prod.yaml "$@"; }

# Les réseaux ont une plage fixée (compose.prod.yaml). Un réseau déjà créé sur une autre plage est recréé par `up`, et les règles de sortie
# de l'hôte (docs/deploy.md) lisent l'ancienne : on le dit (sans écrire d'adresse, le journal est public), sans jamais arrêter le déploiement.
wanted_subnet="${TERRILDB_SUBNET:-10.213.40.0/24}"
current_subnet="$(docker network inspect terrildb-prod_default --format '{{(index .IPAM.Config 0).Subnet}}' 2>/dev/null || true)"
if [ -n "$current_subnet" ] && [ "$current_subnet" != "$wanted_subnet" ]; then
    echo "Attention : le sous-réseau du réseau « default » change. Refaites les règles de sortie du pare-feu de l'hôte sur la nouvelle plage (docs/deploy.md)." >&2
fi

echo "==> Construction de l'image $tag"
TERRILDB_TAG="$tag" compose build app

echo "==> Base interne"
TERRILDB_TAG="$tag" compose up -d --wait db
if [ -n "$previous" ]; then
    backup="$dir/backups/$(date +%Y%m%d-%H%M%S)-$previous.sql.gz"
    partial="$dir/backups/.partial-$$"
    # Privé dès sa création. Le vidage va d'abord dans un fichier, PUIS gzip : dans un tube, `sh` ne verrait que le code de
    # gzip et un pg_dump en échec laisserait une sauvegarde presque vide parmi les bonnes.
    umask 077
    if ! TERRILDB_TAG="$tag" compose exec -T db sh -c 'pg_dump -U "$POSTGRES_USER" "$POSTGRES_DB"' > "$partial" \
        || [ ! -s "$partial" ] || ! gzip -c "$partial" > "$backup.part" || ! mv "$backup.part" "$backup"; then
        rm -f "$partial" "$backup.part"
        echo "Échec de la sauvegarde de la base : déploiement arrêté avant les migrations (sauvegardes existantes intactes)." >&2
        exit 1
    fi
    rm -f "$partial"
    echo "Sauvegarde de la base : $(basename "$backup") ($(wc -c < "$backup" | tr -d ' ') octets)"
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
    # Worker des scripts détachés (#169) : après l'application, et sans jamais défaire son déploiement (sans lui, un script détaché répond
    # « worker indisponible » ; la console, l'API et le MCP ne changent pas).
    if TERRILDB_TAG="$tag" compose up -d --wait script-init script-worker; then
        echo "Worker des scripts démarré."
    else
        echo "Worker des scripts non démarré : l'application reste déployée, les scripts détachés seront refusés (docs/deploy.md)." >&2
    fi
    # Tunnel SSH (facultatif) : démarré seulement si des bastions sont déclarés dans deploy.env. Un échec ici ne défait jamais le
    # déploiement de l'application (sans tunnel, une connexion à rebond répond simplement `tunnel_failed`).
    if [ -n "${TERRILDB_SSH_ALLOWED_BASTIONS:-}" ]; then
        if TERRILDB_TAG="$tag" compose --profile tunnel build tunnel \
            && TERRILDB_TAG="$tag" compose --profile tunnel up -d --wait tunnel-init tunnel; then
            echo "Tunnel SSH démarré (bastions déclarés dans deploy.env)."
        else
            echo "Tunnel SSH non démarré : l'application reste déployée, les connexions à rebond échoueront (docs/ssh-tunnel.md)." >&2
        fi
    else
        compose --profile tunnel rm -sf tunnel tunnel-init >/dev/null 2>&1 || true
    fi
    # nginx et l'application doivent tirer la même limite de durée de la même valeur ; un désaccord est signalé, sans faire échouer
    # un déploiement qu'un retour à l'image précédente ne réparerait pas.
    sh "$root/docker/prod/proxy-timeout.sh" check "${TERRILDB_NGINX_SITE:-/etc/nginx/sites-available/terrildb}" "${TERRILDB_PROXY_TIMEOUT:-120}" || true
    exit 0
fi

echo "Échec du démarrage de $tag :" >&2
TERRILDB_TAG="$tag" compose logs --tail 40 app >&2 || true
if [ -n "$previous" ] && docker image inspect "terrildb:$previous" >/dev/null 2>&1; then
    echo "Retour à $previous (les migrations déjà passées restent appliquées ; sauvegarde dans $dir/backups)." >&2
    TERRILDB_TAG="$previous" compose up -d --wait app || true
fi
exit 1
