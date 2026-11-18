# Réglages communs des conteneurs du cluster Vitess de test (sourcé par les autres scripts).
export VTDATAROOT=/vt/vtdataroot
CELL=zone1
KEYSPACE=shop
SHARD=0
TOPO_FLAGS="--topo-implementation etcd2 --topo-global-server-address vitess-etcd:2379 --topo-global-root /vitess/global"
VTCTLD=vitess-vtctld:15999
PRIMARY_ALIAS=zone1-0000000100
REPLICA_ALIAS=zone1-0000000101

# Sous-commande de vtctldclient vers le vtctld du cluster.
vtctl() { vtctldclient --server "$VTCTLD" "$@"; }
