#!/bin/sh
# Un tablet = SON PROPRE mysqld (mysqlctl) + un vttablet. TABLET_UID : 100 (primaire visé), 101 (réplica).
# Les deux démarrent en REPLICA : le primaire est élu par init.sh (PlannedReparentShard).
set -eu
. "$(dirname "$0")/common.sh"

uid="${TABLET_UID:?}"
dir="$VTDATAROOT/vt_$(printf '%010d' "$uid")"
if [ -d "$dir/data/mysql" ]; then action=start; else action=init; fi
mysqlctl --tablet-uid "$uid" --mysql-port 3306 "$action"

# shellcheck disable=SC2086
exec vttablet $TOPO_FLAGS \
    --tablet-path "$CELL-$(printf '%010d' "$uid")" \
    --tablet-hostname "$(hostname)" \
    --init-keyspace "$KEYSPACE" --init-shard "$SHARD" --init-tablet-type replica \
    --port 15100 --grpc-port 16100 \
    --service-map 'grpc-queryservice,grpc-tabletmanager,grpc-updatestream' \
    --health-check-interval 2s \
    --heartbeat-enable --heartbeat-interval 250ms --heartbeat-on-demand-duration 5s \
    --backup-storage-implementation file --file-backup-storage-root "$VTDATAROOT/backups"
