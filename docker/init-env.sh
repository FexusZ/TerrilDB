#!/bin/sh
# Génère les mots de passe des bases locales (interne + cibles de test) dans docker/dev/, ignoré par git.
# Sans effet si le dossier est déjà rempli. À lancer une fois après le clonage : sh docker/init-env.sh
set -eu

dir="$(cd "$(dirname "$0")" && pwd)/dev"

# Les cibles d'une même famille (MySQL 8.4 et 26, MariaDB 11.8 et 13) partagent leur mot de passe : on ne l'écrit
# qu'une fois. Pour une installation déjà faite, on ajoute à php.env les variables apparues depuis, sans rien changer
# d'autre. Arguments : variable, hôte, fichier du mot de passe, nom du mot de passe.
add_sibling() {
    if grep -q "^$1=" "$dir/php.env"; then
        return 0
    fi
    password="$(sed -n "s/^$4=//p" "$dir/$3")"
    printf '%s=pdo-mysql://root:%s@%s:3306/shop\n' "$1" "$password" "$2" >> "$dir/php.env"
    echo "Ajouté à $dir/php.env : $1"
}

if [ -e "$dir/php.env" ]; then
    add_sibling TARGET_MYSQL26_URL target-mysql-26 target-mysql.env MYSQL_ROOT_PASSWORD
    add_sibling TARGET_MARIADB13_URL target-mariadb-13 target-mariadb.env MARIADB_ROOT_PASSWORD
    if ! grep -q '^TARGET_VITESS_URL=' "$dir/php.env"; then
        echo 'TARGET_VITESS_URL=pdo-mysql://root@target-vitess:33577/shop' >> "$dir/php.env"
        echo "Ajouté à $dir/php.env : TARGET_VITESS_URL"
    fi
    echo "$dir existe déjà : mots de passe inchangés (supprimez-le, puis 'docker compose down -v', pour repartir de zéro)."
    exit 0
fi

secret() { head -c 48 /dev/urandom | base64 | tr -d '/+=\n' | cut -c1-24; }

mkdir -p "$dir"
internal="$(secret)"
mysql="$(secret)"
mariadb="$(secret)"
postgres="$(secret)"
clickhouse="$(secret)"
redis="$(secret)"

printf 'POSTGRES_DB=terrildb\nPOSTGRES_USER=terrildb\nPOSTGRES_PASSWORD=%s\n' "$internal" > "$dir/internal-db.env"
printf 'MYSQL_ROOT_PASSWORD=%s\nMYSQL_DATABASE=shop\n' "$mysql" > "$dir/target-mysql.env"
printf 'MARIADB_ROOT_PASSWORD=%s\nMARIADB_DATABASE=shop\n' "$mariadb" > "$dir/target-mariadb.env"
printf 'POSTGRES_PASSWORD=%s\nPOSTGRES_DB=shop\n' "$postgres" > "$dir/target-postgres.env"
printf 'CLICKHOUSE_USER=tester\nCLICKHOUSE_PASSWORD=%s\nCLICKHOUSE_DB=shop\nCLICKHOUSE_DEFAULT_ACCESS_MANAGEMENT=1\n' "$clickhouse" > "$dir/target-clickhouse.env"
printf 'REDIS_PASSWORD=%s\n' "$redis" > "$dir/target-redis.env"
cat > "$dir/php.env" <<ENV
DATABASE_URL=pdo-pgsql://terrildb:$internal@db:5432/terrildb
TARGET_MYSQL_URL=pdo-mysql://root:$mysql@target-mysql:3306/shop
TARGET_MYSQL26_URL=pdo-mysql://root:$mysql@target-mysql-26:3306/shop
TARGET_MARIADB_URL=pdo-mysql://root:$mariadb@target-mariadb:3306/shop
TARGET_MARIADB13_URL=pdo-mysql://root:$mariadb@target-mariadb-13:3306/shop
TARGET_VITESS_URL=pdo-mysql://root@target-vitess:33577/shop
TARGET_POSTGRES_URL=pdo-pgsql://postgres:$postgres@target-postgres:5432/shop
TARGET_CLICKHOUSE_URL=clickhouse://tester:$clickhouse@target-clickhouse:8123/shop
TARGET_REDIS_URL=redis://default:$redis@target-redis:6379/15
ENV
chmod 600 "$dir"/*.env
echo "Mots de passe générés dans $dir"
