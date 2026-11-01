#!/bin/sh
# Génère les mots de passe des bases locales (interne + cibles de test) dans docker/dev/, ignoré par git.
# Sans effet si le dossier est déjà rempli. À lancer une fois après le clonage : sh docker/init-env.sh
set -eu

dir="$(cd "$(dirname "$0")" && pwd)/dev"
if [ -e "$dir/php.env" ]; then
    echo "$dir existe déjà : rien à faire (supprimez-le, puis 'docker compose down -v', pour repartir de zéro)."
    exit 0
fi

secret() { head -c 48 /dev/urandom | base64 | tr -d '/+=\n' | cut -c1-24; }

mkdir -p "$dir"
internal="$(secret)"
mysql="$(secret)"
mariadb="$(secret)"
postgres="$(secret)"
clickhouse="$(secret)"

printf 'POSTGRES_DB=terrildb\nPOSTGRES_USER=terrildb\nPOSTGRES_PASSWORD=%s\n' "$internal" > "$dir/internal-db.env"
printf 'MYSQL_ROOT_PASSWORD=%s\nMYSQL_DATABASE=shop\n' "$mysql" > "$dir/target-mysql.env"
printf 'MARIADB_ROOT_PASSWORD=%s\nMARIADB_DATABASE=shop\n' "$mariadb" > "$dir/target-mariadb.env"
printf 'POSTGRES_PASSWORD=%s\nPOSTGRES_DB=shop\n' "$postgres" > "$dir/target-postgres.env"
printf 'CLICKHOUSE_USER=tester\nCLICKHOUSE_PASSWORD=%s\nCLICKHOUSE_DB=shop\nCLICKHOUSE_DEFAULT_ACCESS_MANAGEMENT=1\n' "$clickhouse" > "$dir/target-clickhouse.env"
cat > "$dir/php.env" <<ENV
DATABASE_URL=pdo-pgsql://terrildb:$internal@db:5432/terrildb
TARGET_MYSQL_URL=pdo-mysql://root:$mysql@target-mysql:3306/shop
TARGET_MARIADB_URL=pdo-mysql://root:$mariadb@target-mariadb:3306/shop
TARGET_POSTGRES_URL=pdo-pgsql://postgres:$postgres@target-postgres:5432/shop
TARGET_CLICKHOUSE_URL=clickhouse://tester:$clickhouse@target-clickhouse:8123/shop
ENV
chmod 600 "$dir"/*.env
echo "Mots de passe générés dans $dir"
