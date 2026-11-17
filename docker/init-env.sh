#!/bin/sh
# Génère les mots de passe des bases locales (interne + cibles de test) et l'APP_SECRET de développement dans docker/dev/, ignoré par git.
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

secret() { head -c 48 /dev/urandom | base64 | tr -d '/+=\n' | cut -c1-24; }

# Profil « replication » (paires source / réplica jetables, voir docs/replication-tests.md) : un fichier de mots de passe par
# moteur (créé s'il manque, jamais réécrit) et les variables des tests ajoutées à php.env (jamais réécrites non plus).
# Arguments : fichier, contenu à écrire s'il manque.
replication_file() {
    if [ ! -e "$dir/$1" ]; then
        printf '%s\n' "$2" > "$dir/$1"
        chmod 600 "$dir/$1"
        echo "Créé : $dir/$1"
    fi
}
# Arguments : variable, fichier du mot de passe, nom du mot de passe, modèle d'URL (%s = le mot de passe).
replication_url() {
    if grep -q "^$1=" "$dir/php.env"; then
        return 0
    fi
    password="$(sed -n "s/^$3=//p" "$dir/$2")"
    # shellcheck disable=SC2059
    printf "$1=$4\n" "$password" >> "$dir/php.env"
    echo "Ajouté à $dir/php.env : $1"
}
add_replication() {
    replication_file replication-postgres.env "POSTGRES_PASSWORD=$(secret)"
    replication_file replication-mysql.env "MYSQL_ROOT_PASSWORD=$(secret)"
    replication_file replication-mariadb.env "MARIADB_ROOT_PASSWORD=$(secret)"
    replication_file replication-clickhouse.env "CLICKHOUSE_USER=tester
CLICKHOUSE_PASSWORD=$(secret)
CLICKHOUSE_DEFAULT_ACCESS_MANAGEMENT=1"
    replication_file replication-redis.env "REDIS_PASSWORD=$(secret)"
    replication_url TARGET_REPLICATED_POSTGRES_URL replication-postgres.env POSTGRES_PASSWORD 'pdo-pgsql://postgres:%s@repl-pg-primary:5432/postgres'
    replication_url TARGET_REPLICA_POSTGRES_URL replication-postgres.env POSTGRES_PASSWORD 'pdo-pgsql://postgres:%s@repl-pg-replica:5432/postgres'
    replication_url TARGET_REPLICA_MYSQL_URL replication-mysql.env MYSQL_ROOT_PASSWORD 'pdo-mysql://root:%s@repl-mysql-replica:3306/mysql'
    replication_url TARGET_SEMISYNC_MYSQL_URL replication-mysql.env MYSQL_ROOT_PASSWORD 'pdo-mysql://root:%s@repl-mysql-semisync:3306/mysql'
    replication_url TARGET_REPLICA_MARIADB_URL replication-mariadb.env MARIADB_ROOT_PASSWORD 'pdo-mysql://root:%s@repl-mariadb-replica:3306/mysql'
    replication_url TARGET_BINLOG_MARIADB_URL replication-mariadb.env MARIADB_ROOT_PASSWORD 'pdo-mysql://root:%s@repl-mariadb-binlog:3306/mysql'
    replication_url TARGET_REPLICATED_CLICKHOUSE_URL replication-clickhouse.env CLICKHOUSE_PASSWORD 'clickhouse://tester:%s@repl-clickhouse:8123/d'
    replication_url TARGET_REPLICATED_REDIS_URL replication-redis.env REDIS_PASSWORD 'redis://default:%s@repl-redis-primary:6379/15'
    replication_url TARGET_REPLICA_REDIS_URL replication-redis.env REDIS_PASSWORD 'redis://default:%s@repl-redis-replica:6379/15'
}

# Secret du framework (sessions, jetons CSRF) : 64 caractères hexadécimaux, propre à chaque poste, jamais versionné.
app_secret() { head -c 32 /dev/urandom | od -An -tx1 | tr -d ' \n'; }

if [ -e "$dir/php.env" ]; then
    if ! grep -q '^APP_SECRET=' "$dir/php.env"; then
        printf 'APP_SECRET=%s\n' "$(app_secret)" >> "$dir/php.env"
        echo "Ajouté à $dir/php.env : APP_SECRET"
    fi
    add_sibling TARGET_MYSQL26_URL target-mysql-26 target-mysql.env MYSQL_ROOT_PASSWORD
    add_sibling TARGET_MARIADB13_URL target-mariadb-13 target-mariadb.env MARIADB_ROOT_PASSWORD
    add_replication
    echo "$dir existe déjà : mots de passe inchangés (supprimez-le, puis 'docker compose down -v', pour repartir de zéro)."
    exit 0
fi

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
APP_SECRET=$(app_secret)
DATABASE_URL=pdo-pgsql://terrildb:$internal@db:5432/terrildb
TARGET_MYSQL_URL=pdo-mysql://root:$mysql@target-mysql:3306/shop
TARGET_MYSQL26_URL=pdo-mysql://root:$mysql@target-mysql-26:3306/shop
TARGET_MARIADB_URL=pdo-mysql://root:$mariadb@target-mariadb:3306/shop
TARGET_MARIADB13_URL=pdo-mysql://root:$mariadb@target-mariadb-13:3306/shop
TARGET_POSTGRES_URL=pdo-pgsql://postgres:$postgres@target-postgres:5432/shop
TARGET_CLICKHOUSE_URL=clickhouse://tester:$clickhouse@target-clickhouse:8123/shop
TARGET_REDIS_URL=redis://default:$redis@target-redis:6379/15
ENV
add_replication
chmod 600 "$dir"/*.env
echo "Mots de passe générés dans $dir"
