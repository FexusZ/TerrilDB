# Cible de test Vitess

Un seul conteneur (`target-vitess`, profil `vitess`) fait tourner `vttestserver` : un keyspace `shop` non partagé avec des tablets
**primaire**, **réplica** et **lecture seule**, plus un vtgate qui parle le protocole MySQL. Le jeu de données est celui des autres cibles
(`docker/targets/mysql`, 7 000 lignes dans `order_items`).

**Limite importante** : `vttestserver` (vtcombo) n'a qu'UN `mysqld` pour toute la partition. Les tablets réplica et lecture seule
sont des étiquettes de routage sur le même serveur : il n'y a pas de réplication réelle, pas de retard, et `@@read_only` y vaut 0 parce
que c'est le primaire qui répond. Cette cible ne permet donc PAS de tester la lecture seule d'un réplica (#39) ni la réplication.
Pour cela, voir « Vrai cluster Vitess » plus bas.

```bash
sh docker/init-env.sh                                       # ajoute TARGET_VITESS_URL à docker/dev/php.env
docker compose --profile vitess up -d --wait target-vitess  # ~1,5 Go de mémoire
docker compose --profile vitess down                        # arrêt (les données sont jetables)
```

- Accès depuis l'hôte : `127.0.0.1:33577`, utilisateur `root`, sans mot de passe. Depuis le conteneur `php` : `TARGET_VITESS_URL`
  (`pdo-mysql://root@target-vitess:33577/shop`).
- Version annoncée : `8.0.35-Vitess` (`@@version` se termine par `-Vitess`, c'est ce que le driver doit détecter).
- Routage : `USE \`shop@replica\`` (ou `shop@primary`, `shop@rdonly`) choisit le type de tablet visé. `SHOW VITESS_TABLETS` les liste.
- Hors profil `targets` et hors CI : plus lourd et plus lent à démarrer que les autres cibles.

## Constats déjà vérifiés sur cette cible

- #38 : `SET SESSION MAX_EXECUTION_TIME` est accepté mais pas transmis (`@@session.max_execution_time` reste 0, `SELECT SLEEP(2)` n'est
  pas coupé) ; `/*vt+ QUERY_TIMEOUT_MS=1000 */` coupe bien (erreur 3024).
- #39 : NON vérifiable ici (un seul `mysqld`, voir la limite ci-dessus) ; le `@@read_only` = 0 vu « sur le réplica » ne prouve rien.

# Vrai cluster Vitess (profil `vitess-cluster`)

La cible ci-dessus ne répond pas aux questions de réplication. Le profil `vitess-cluster` monte un vrai cluster, à côté de
`vitess` et de `targets` (jamais démarré par la CI) :

| Service | Rôle |
|---|---|
| `vitess-etcd` | topologie (etcd 3.5) |
| `vitess-vtctld` | vtctld + cellule `zone1` + keyspace `shop` non partagé, une partition (`0`) |
| `vitess-tablet-primary` (uid 100) | `mysqld` PROPRE (mysqlctl, MySQL 8.4.6) + vttablet |
| `vitess-tablet-replica` (uid 101) | autre `mysqld` PROPRE + vttablet, répliqué depuis le primaire (GTID) |
| `vitess-vtgate` | protocole MySQL, sans mot de passe (`root`), publié sur `127.0.0.1:33578` |
| `vitess-init` | une fois : élection du primaire (`PlannedReparentShard`), schéma et données du jeu `shop` (7 000 lignes) |

Image `vitess/lite:v23.0.0` (versions épinglées, aucune mise à jour automatique des majeures). Scripts shell idempotents dans
`docker/vitess-cluster/` (`init.sh` se rejoue sans dégât). Les deux tablets démarrent en REPLICA ; le primaire est élu par
`init.sh`. Santé de vtgate = `check.sh` : un PRIMARY et un REPLICA `SERVING`, 7 000 lignes lues sur `shop@primary` ET `shop@replica`.

```bash
sh docker/init-env.sh        # ajoute TARGET_VITESS_CLUSTER_URL (pdo-mysql://root@vitess-vtgate:15306/shop)
docker compose --profile vitess-cluster up -d vitess-init vitess-vtgate    # nommer les services : sinon php et db démarrent aussi
docker compose --profile vitess-cluster ps -a                              # vitess-init sort en code 0 : c'est normal
docker compose run --rm --no-deps php vendor/bin/phpunit --group integration tests/Integration/Driver/TargetVitessClusterTest.php
docker compose --profile vitess-cluster down -v                            # remet tout à zéro
```

Avec un projet compose séparé (`-p vitess-cluster`), ajouter `-p` à chaque commande, `run php` rejoint alors son réseau.
Depuis l'hôte : `mysql -h127.0.0.1 -P33578 -uroot -D shop@replica`. Le type de tablet visé se choisit par la base (`-D shop@primary`,
`USE \`shop@replica\``) ; `shop@replica.table` (qualification dans la requête) n'est PAS accepté par vtgate.

Mesuré (machine de 16 cœurs, images déjà tirées) : cluster prêt (healthcheck vtgate vert) en ~75 s à froid, ~1 Go de mémoire au total
(chaque tablet ~500 Mo ; mysqld compris). Premier tirage des images : ~1,5 Go (`vitess/lite`) à télécharger.

## Constats vérifiés sur ce cluster

- `SHOW VITESS_TABLETS` : un `PRIMARY` et un `REPLICA`, tous deux `SERVING`, hôtes (conteneurs) différents.
- `@@server_id` et `@@server_uuid` différents entre `shop@primary` et `shop@replica` : deux `mysqld`.
- Réplication réelle (GTID, `Replica_IO_Running` / `Replica_SQL_Running` = Yes, `Seconds_Behind_Source` = 0) : une table créée et une
  ligne insérée sur `shop@primary` se lisent sur `shop@replica` une seconde après ; `gtid_executed` identiques.
- **#39** : sur le `mysqld` du tablet réplica, `@@read_only = 1` ET `@@super_read_only = 1` (le `my.cnf` de Vitess le démarre en
  `super-read-only` et vttablet ne le relâche que sur le primaire, où ils valent 0). Le moteur impose donc bien la lecture seule du
  réplica. MAIS un client qui passe par vtgate sur `shop@replica` n'atteint jamais cette garde : vtgate refuse déjà l'écriture
  (`ERROR 1105 : supported only for primary tablet type, current type: replica`), et `SET SESSION TRANSACTION READ WRITE` n'y change rien.
  Le `mysqld` d'un tablet n'est pas joignable hors du réseau compose (socket seulement, ni mot de passe ni port publié).
  Conséquence pour TerrilDB : la lecture seule du réplica est imposée par Vitess (vtgate + `super_read_only`), pas par TerrilDB ; la
  « transaction read-only du moteur » ne distingue rien ici, c'est le type de tablet de la base (`@replica`) qui fait foi.
- Sans cible (`shop` seul), `@@read_only` vaut 0 : vtgate route vers le primaire.

## Limites

- Un seul shard, pas de vtorc : après un redémarrage d'un tablet, rejouer `vitess-init` (il réélit le primaire s'il manque).
  Pas de basculement automatique, pas de sauvegarde, pas de retard de réplication simulé.
- Les noms d'hôte des tablets sont les identifiants de conteneur (régénérés à chaque création).
- Hors CI, hors profil `targets` ; `down -v` détruit les deux disques de données.
