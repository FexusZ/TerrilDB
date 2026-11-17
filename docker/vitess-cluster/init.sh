#!/bin/sh
# Élit le primaire, charge le schéma et les données du jeu "shop" (docker/targets/mysql). Idempotent : rejouable à chaud.
set -eu
# Le client mysql lit le mot de passe dans MYSQL_PWD (variable du conteneur, jamais sur la ligne de commande).
export MYSQL_PWD="${VITESS_ROOT_PASSWORD:?VITESS_ROOT_PASSWORD manquant}"
. "$(dirname "$0")/common.sh"

until vtctl GetTablets --keyspace "$KEYSPACE" 2>/dev/null | grep -q "$PRIMARY_ALIAS" \
    && vtctl GetTablets --keyspace "$KEYSPACE" 2>/dev/null | grep -q "$REPLICA_ALIAS"; do
    echo "en attente des deux tablets"; sleep 2
done

# Pas de primaire encore (ou plus) : élection ordonnée (les deux tablets sont sains, aucun risque de perte).
if ! vtctl GetTablets --keyspace "$KEYSPACE" --tablet-type primary 2>/dev/null | grep -q "$KEYSPACE"; then
    vtctl PlannedReparentShard "$KEYSPACE/$SHARD" --new-primary "$PRIMARY_ALIAS"
fi

# Le jeu est chargé une seule fois : schéma s'il manque, données si order_items n'a pas ses 7 000 lignes (reprise après un échec en route).
mysqlvt() { mysql -hvitess-vtgate -P15306 -uroot -D "$KEYSPACE@primary" "$@"; }
# vtgate voit le primaire quelques secondes après l'élection : on attend qu'il sache router une lecture vers lui.
until mysqlvt -N -e 'show tables' >/dev/null 2>&1; do echo "en attente de vtgate"; sleep 2; done
if ! mysqlvt -N -e 'show tables' | grep -qx order_items; then
    vtctl ApplySchema --sql-file /schema/01-schema.sql "$KEYSPACE"
    vtctl ApplySchema --sql-file /schema/03-views.sql "$KEYSPACE"
fi
if [ "$(mysqlvt -N -e 'select count(*) from order_items')" != 7000 ]; then
    mysqlvt -e 'set foreign_key_checks = 0; truncate order_items; truncate orders; truncate products; truncate customers; truncate categories'
    mysqlvt < /schema/02-data.sql
fi
echo "cluster prêt"
