#!/bin/sh
# vtctld + cellule + keyspace "shop" non partagé. Idempotent : on relit la topologie avant de créer.
set -eu
. "$(dirname "$0")/common.sh"

# shellcheck disable=SC2086
vtctld $TOPO_FLAGS --cell "$CELL" --service-map 'grpc-vtctl,grpc-vtctld' --port 15000 --grpc-port 15999 \
    --backup-storage-implementation file --file-backup-storage-root "$VTDATAROOT/backups" &
pid=$!

until vtctl GetCellInfoNames >/dev/null 2>&1; do sleep 1; done
if ! vtctl GetCellInfoNames | grep -qx "$CELL"; then
    vtctl AddCellInfo --root "/vitess/$CELL" --server-address vitess-etcd:2379 "$CELL"
fi
if ! vtctl GetKeyspaces | grep -qx "$KEYSPACE"; then
    vtctl CreateKeyspace --durability-policy none "$KEYSPACE"
fi
touch /tmp/vtctld-ready
wait "$pid"
