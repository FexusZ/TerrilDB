#!/bin/sh
# Réplica jetable : au premier démarrage, copie le primaire (pg_basebackup, slot réservé, standby.signal posé par -R) puis sert en lecture.
set -eu
if [ ! -s "$PGDATA/PG_VERSION" ]; then
    mkdir -p "$PGDATA"
    until PGPASSWORD="$POSTGRES_PASSWORD" pg_basebackup -h repl-pg-primary -U postgres -D "$PGDATA" -R -X stream -S repl_slot; do
        find "$PGDATA" -mindepth 1 -delete
        sleep 2
    done
    chmod 700 "$PGDATA"
fi
exec postgres
