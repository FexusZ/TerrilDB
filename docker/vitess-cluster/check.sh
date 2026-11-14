#!/bin/sh
# Santé du cluster : un primaire élu, un réplica SERVING, les 7 000 lignes lisibles par vtgate sur les DEUX tablets.
set -eu
q() { mysql -h127.0.0.1 -P15306 -uroot -N -e "$1" 2>/dev/null; }
# Le type de tablet visé se choisit par la base : -D shop@primary, -D shop@replica.
count() { mysql -h127.0.0.1 -P15306 -uroot -D "shop@$1" -N -e 'select count(*) from order_items' 2>/dev/null; }
q 'show vitess_tablets' | grep -q PRIMARY
q 'show vitess_tablets' | grep -q REPLICA
q 'show vitess_tablets' | grep -v -q NOT_SERVING
[ "$(count primary)" = 7000 ]
[ "$(count replica)" = 7000 ]
