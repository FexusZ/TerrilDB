# Paires de réplication de test

Les cibles habituelles (profil `targets`) n'ont pas de réplica : `tests/Integration/Driver/ReplicationDiagnosticsTest`
saute donc les cas qui lisent une vraie paire. Le profil Compose `replication` les fournit. Les services sont jetables,
préfixés `repl-`, et n'utilisent ni ne modifient les cibles partagées.

## Démarrage

```bash
sh docker/init-env.sh        # une fois : mots de passe (docker/dev/replication-*.env) et variables TARGET_* de php.env
docker compose --profile replication up -d --wait
R="docker compose run --rm --no-deps php"
$R vendor/bin/phpunit --group integration --filter ReplicationDiagnostics
```

`init-env.sh` complète une installation existante sans rien réécrire : seuls les fichiers et variables manquants sont ajoutés.
On peut aussi démarrer un seul moteur, par exemple `up -d --wait repl-pg-replica`.

## Services

| Service | Rôle | Variable de test |
| --- | --- | --- |
| `repl-pg-primary`, `repl-pg-replica` | PostgreSQL 18, streaming asynchrone, slot `repl_slot` | `TARGET_REPLICATED_POSTGRES_URL`, `TARGET_REPLICA_POSTGRES_URL` |
| `repl-redis-primary`, `repl-redis-replica` | Redis 8, source et réplica | `TARGET_REPLICATED_REDIS_URL`, `TARGET_REPLICA_REDIS_URL` |
| `repl-clickhouse` | ClickHouse avec Keeper embarqué (un seul noeud), table `d.rt` en ReplicatedMergeTree | `TARGET_REPLICATED_CLICKHOUSE_URL` |
| `repl-mysql-source`, `repl-mysql-replica` | MySQL 8.4, GTID ; le réplica n'a pas de journal binaire (aucune transaction errante) et est en `read_only` | `TARGET_REPLICA_MYSQL_URL` |
| `repl-mysql-semisync` | MySQL 8.4 seul, greffon semi-synchrone chargé, journal binaire et GTID | `TARGET_SEMISYNC_MYSQL_URL` |
| `repl-mariadb-source`, `repl-mariadb-replica` | MariaDB 11.8, GTID (`slave_pos`) | `TARGET_REPLICA_MARIADB_URL` |
| `repl-mariadb-binlog` | MariaDB 11.8 seul, journal binaire sans expiration (`--binlog-expire-logs-seconds=0` : 11.8 expire à 10 jours par défaut) | `TARGET_BINLOG_MARIADB_URL` |

Ports publiés sur 127.0.0.1 seulement (54341-54342, 63792-63793, 18124, 33071-33076). Les scripts d'initialisation sont dans
`docker/replication/`, sans aléatoire ; les mots de passe viennent de `docker/dev/replication-*.env` (ignorés par git).

## Limites

- ClickHouse : un seul noeud, donc une table répliquée sans second réplica ; le retard et les files entre deux noeuds ne sont pas exercés.
- Pas de Group Replication, de Galera ni de Vitess ici (voir les profils `vitess` et `vitess-cluster`).
- Après un arrêt brutal, les volumes anonymes gardent l'ancien état : `docker compose --profile replication rm -sfv <services>` repart de zéro (sans toucher aux autres profils).
- Le test « compte sans droit » de PostgreSQL / MySQL / MariaDB / ClickHouse / Redis des cibles habituelles reste lié au profil `targets`.
