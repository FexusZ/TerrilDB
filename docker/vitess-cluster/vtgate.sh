#!/bin/sh
# vtgate : protocole MySQL sur 15306, sans mot de passe (root), cible de test jetable.
set -eu
. "$(dirname "$0")/common.sh"

# shellcheck disable=SC2086
exec vtgate $TOPO_FLAGS --cell "$CELL" --cells-to-watch "$CELL" --tablet-types-to-wait PRIMARY,REPLICA \
    --port 15001 --grpc-port 15991 --service-map 'grpc-vtgateservice' \
    --mysql-server-bind-address 0.0.0.0 --mysql-server-port 15306 --mysql-auth-server-impl none \
    --mysql-server-version 8.4.6-Vitess
