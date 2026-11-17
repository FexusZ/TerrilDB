#!/bin/sh
# vtgate : protocole MySQL sur 15306, authentification statique : un seul compte, root, dont le mot de passe vient de
# VITESS_ROOT_PASSWORD (compose, env_file docker/dev/target-vitess.env). Le fichier des utilisateurs est écrit ICI, au démarrage,
# dans le conteneur : jamais versionné.
set -eu
. "$(dirname "$0")/common.sh"

: "${VITESS_ROOT_PASSWORD:?VITESS_ROOT_PASSWORD manquant : sh docker/init-env.sh}"
users=/tmp/vtgate-users.json
umask 077
printf '{"root": [{"Password": "%s"}]}\n' "$VITESS_ROOT_PASSWORD" > "$users"

# shellcheck disable=SC2086
exec vtgate $TOPO_FLAGS --cell "$CELL" --cells-to-watch "$CELL" --tablet-types-to-wait PRIMARY,REPLICA \
    --port 15001 --grpc-port 15991 --service-map 'grpc-vtgateservice' \
    --mysql-server-bind-address 0.0.0.0 --mysql-server-port 15306 --mysql-auth-server-impl static --mysql-auth-server-static-file $users \
    --mysql-server-version 8.4.6-Vitess
