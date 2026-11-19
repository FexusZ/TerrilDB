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

- Accès depuis l'hôte : `127.0.0.1:33577`, utilisateur `root`, SANS mot de passe (`vttestserver` n'a aucun réglage d'authentification pour son vtgate, vérifié sur l'image `mysql80` : c'est la seule cible de test sans mot de passe, nommée dans `TargetDatabase`). Depuis le conteneur `php` : `TARGET_VITESS_URL`
  (`pdo-mysql://root@target-vitess:33577/shop`).
- Version annoncée : `8.0.35-Vitess` (`@@version` se termine par `-Vitess` ; le runner de lecture seule ne s'en sert pas, voir #39).
- Routage : `USE \`shop@replica\`` (ou `shop@primary`, `shop@rdonly`) choisit le type de tablet visé. `SHOW VITESS_TABLETS` les liste.
- Hors profil `targets` et hors CI : plus lourd et plus lent à démarrer que les autres cibles.

## Constats déjà vérifiés sur cette cible

- #38 : `SET SESSION MAX_EXECUTION_TIME` est accepté mais pas transmis (`@@session.max_execution_time` reste 0, `SELECT SLEEP(2)` n'est
  pas coupé) ; `/*vt+ QUERY_TIMEOUT_MS=1000 */` coupe bien (erreur 3024). Corrigé (PR #111) : le driver sait que la cible est Vitess par la version lue une fois à l'ouverture (`ServerFlavour::Vitess`) et injecte `/*vt+ QUERY_TIMEOUT_MS=n */` après le premier mot-clé (SELECT, INSERT, UPDATE, DELETE, REPLACE ; sans effet sur SHOW et WITH) ; vtgate répond par l'erreur 3024 = `query_timeout`. Seul le délai en dépend, pas la lecture seule.
- #40 (bases) : `information_schema.SCHEMATA` rend `["mysql", "information_schema", "performance_schema", "sys", "_vt", "vt_shop_0"]` (les bases physiques du tablet) alors que `SHOW DATABASES` rend `["shop", "information_schema", "mysql", "sys", "performance_schema"]` et `SHOW KEYSPACES` `["shop"]`. Corrigé : sur Vitess la liste des bases vient de `SHOW DATABASES` (`VitessKeyspacesTest`).
- #39 : NON vérifiable ici (un seul `mysqld`, voir la limite ci-dessus) ; le `@@read_only` = 0 vu « sur le réplica » ne prouve rien. Voir le vrai cluster plus bas.

# Vrai cluster Vitess (profil `vitess-cluster`)

La cible ci-dessus ne répond pas aux questions de réplication. Le profil `vitess-cluster` monte un vrai cluster, à côté de
`vitess` et de `targets` (jamais démarré par la CI) :

| Service | Rôle |
|---|---|
| `vitess-etcd` | topologie (etcd 3.5) |
| `vitess-vtctld` | vtctld + cellule `zone1` + keyspace `shop` non partagé, une partition (`0`) |
| `vitess-tablet-primary` (uid 100) | `mysqld` PROPRE (mysqlctl, MySQL 8.4.6) + vttablet |
| `vitess-tablet-replica` (uid 101) | autre `mysqld` PROPRE + vttablet, répliqué depuis le primaire (GTID) |
| `vitess-vtgate` | protocole MySQL, authentification statique (`root` + mot de passe tiré par `init-env.sh`, `docker/dev/target-vitess.env`, fichier des utilisateurs écrit au démarrage dans le conteneur), publié sur `127.0.0.1:33578` |
| `vitess-init` | une fois : élection du primaire (`PlannedReparentShard`), schéma et données du jeu `shop` (7 000 lignes) |

Image `vitess/lite:v23.0.0` (versions épinglées, aucune mise à jour automatique des majeures). Scripts shell idempotents dans
`docker/vitess-cluster/` (`init.sh` se rejoue sans dégât). Les deux tablets démarrent en REPLICA ; le primaire est élu par
`init.sh`. Santé de vtgate = `check.sh` : un PRIMARY et un REPLICA `SERVING`, 7 000 lignes lues sur `shop@primary` ET `shop@replica`.

```bash
sh docker/init-env.sh        # crée docker/dev/target-vitess.env et TARGET_VITESS_CLUSTER_URL (pdo-mysql://root:<mot de passe>@vitess-vtgate:15306/shop) ; une ancienne URL sans mot de passe est complétée
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

## #39 : lecture seule, constats mesurés (via le runner de TerrilDB, `VitessReadOnlyTest`)

Énoncé initial rectifié : « la lecture seule n'est pas imposée par le moteur » est VRAI pour le primaire seulement ; un réplica
la fait respecter (voir plus haut). Mesuré par `MySqlQueryRunner` en mode lecture seule, AVANT correctif :

| Cible | INSERT / UPDATE / DELETE | CREATE / DROP / ALTER / TRUNCATE |
|---|---|---|
| `shop` et `shop@primary` | PASSENT (le moteur n'imposait rien) | PASSENT |
| `shop@replica` | refusés par vtgate (1105 « supported only for primary tablet type ») | refusés par le mysqld (1290, `--read-only`) |

Cause : vtgate accepte `SET SESSION TRANSACTION READ ONLY` (et `SET SESSION transaction_read_only=1`) mais ne le transmet pas
(`@@session.transaction_read_only` reste 0). Autres mesures, sur `shop` et `shop@primary` :

- `START TRANSACTION READ ONLY` : INSERT, UPDATE, DELETE refusés (SQLSTATE 25006, erreur 1792 du tablet), lectures permises ;
  MAIS CREATE, ALTER, TRUNCATE et DROP s'exécutent quand même (vtgate les joue hors de la transaction).
- `SET GLOBAL read_only=1` : accepté, sans effet (`@@global.read_only` reste 0) ; `SET read_only=1` : refusé (VT05006).
- `SET SESSION max_execution_time` : accepté, non appliqué (#38) ; `sql_mode` : appliqué ; `SET @a` : conservé.
- `SELECT GET_LOCK(...)` mêlé à d'autres colonnes : refusé par vtgate (« allowed only with dual ») ; `SELECT … INTO OUTFILE` : refusé
  par le mysqld ; `LOAD_FILE` rend NULL ; `CALL` inconnu : erreur 1305 ; `ALTER VITESS_MIGRATION` : refusé sur un réplica.

Correctif (groupe A, PR #156) : en lecture seule le runner MySQL ouvre TOUJOURS `START TRANSACTION READ ONLY`, sans détecter Vitess
(une version renommée ou illisible aurait laissé passer les écritures) : les écritures de données sont refusées PAR LE TABLET. Le
DDL reste borné par le garde seul sur le primaire (aucune barrière du moteur) : limite connue, verrouillée par
`testTheEngineAloneDoesNotStopDdlOnThePrimary`. Coût : transaction coupée à 30 s (`--queryserver-config-transaction-timeout`).
Barrière complète possible : lire sur `@replica` (voir #40). Ce dépôt apporte la preuve sur le vrai cluster.

```bash
docker compose run --rm --no-deps php vendor/bin/phpunit --group integration tests/Integration/Driver/VitessReadOnlyTest.php
```

## #40 : ce qui justifierait `Engine::Vitess` (analyse, rien de codé)

Aujourd'hui Vitess est « MySQL » : le runner de lecture seule ne le détecte pas (il ouvre toujours la transaction en lecture seule). Mesuré sur le cluster
(Vitess 23, `@@version` = `8.4.6-Vitess`) :

1. Détection : `@@version` finit par `-Vitess` ; MariaDB n'est jamais derrière vtgate. Un `Engine` distinct la ferait une fois pour tout
   le driver (introspecteur, DDL, données, scripts), pas seulement le runner.
2. Cible de tablet : la base de la connexion porte le suffixe (`shop@replica`, `@rdonly`, `@primary`). Une connexion « lecture » sur
   `@replica` donne une lecture seule imposée par le moteur et allège le primaire ; contrepartie : retard de réplication, repli ou
   échec sans réplica servant à décider.
3. Délais : `SET SESSION MAX_EXECUTION_TIME` non transmis, remplacé par `/*vt+ QUERY_TIMEOUT_MS=n */` (#38, PR #111) ; la transaction de
   lecture seule est coupée à 30 s : la durée utile est le plus petit des deux.
4. Lecture seule : voir ci-dessus ; un `Engine::Vitess` porterait la règle « primaire = `START TRANSACTION READ ONLY` + garde pour le
   DDL, réplica = moteur ».
5. Bases : `information_schema.SCHEMATA` rend les bases PHYSIQUES (`vt_shop`, `_vt`, `mysql`, `sys`), pas les keyspaces ; `SHOW DATABASES`
   rend `shop` + les bases système. `listDatabases` devrait lire les keyspaces et masquer `_vt` / `vt_*`.
6. Diagnostics : `information_schema.PROCESSLIST` et `performance_schema.processlist` répondent mais décrivent le mysqld DU TABLET
   interrogé (comptes internes `vt_app`, `vt_repl`, `vt_allprivs`, jamais les sessions des clients de vtgate, qui les multiplexe) ;
   `INNODB_TRX` vide. Les thèmes Sessions / Transactions / Verrous / Empreintes / Variables seraient trompeurs : à marquer `unsupported`
   ou à lire par tablet (`SHOW VITESS_TABLETS`, `SHOW VITESS_REPLICATION_STATUS`).
7. Plan : `EXPLAIN` et `EXPLAIN FORMAT=JSON` sont transmis au tablet (plan du mysqld, pas du routage) ; `EXPLAIN FORMAT=vtexplain` n'existe
   pas ; le plan de routage de vtgate est `EXPLAIN FORMAT=vitess` / `VEXPLAIN` (à mesurer). Les tables visibles dépendent du VSchema
   (`SHOW VSCHEMA TABLES`).
8. DDL en ligne : `ALTER VITESS_MIGRATION '<uuid>' CANCEL|RETRY|COMPLETE…`, `SHOW VITESS_MIGRATIONS`, `@@ddl_strategy` (`online`, `vitess`) : un
   `ALTER` direct bloque ; le garde voit `ALTER VITESS_MIGRATION` comme un ALTER ordinaire. Avertissement utile : un `ALTER` sur la source
   d'un `Materialize` n'est pas propagé (information par `/debug/vschema` de vtgate ou vtadmin, pas en SQL).
9. Verbes propres : `SHOW VITESS_TABLETS`, `SHOW VITESS_SHARDS`, `SHOW VITESS_REPLICATION_STATUS`, `SHOW VITESS_MIGRATIONS`,
   `SHOW VSCHEMA TABLES`, `VEXPLAIN` : le garde lexical les voit comme inconnus.

Découpage proposé en PR (chacune : tests d'abord, sur le vrai cluster) :

1. `Engine::Vitess` + détection à l'ouverture (`@@version`), servi par `MySqlDriver` (absorbe #111) ; le correctif de lecture seule
   n'en dépend pas et n'en a pas besoin.
2. Lecture sur `@replica` en option de connexion, avec test du retard et du repli ; le primaire reste inchangé.
3. Bases = keyspaces (`listDatabases`), masquage des bases internes.
4. Diagnostics : thèmes `unsupported` (ou lus par tablet) pour Vitess, testés sur le cluster.
5. Garde et DDL : verbes `VITESS_MIGRATION` / `SHOW VITESS_*` (liste blanche en lecture), stratégie en ligne dans l'éditeur de structure.
6. Plan : `EXPLAIN FORMAT=vitess` en complément (mesure préalable), avertissement `Materialize`.

# Lire sur les réplicas (option de connexion, #40)

`readReplica` (formulaire « Lire sur les réplicas », champ `readReplica` de la création et de la modification, MySQL seulement) est
éteinte par défaut. Allumée, la cible `@replica` est ajoutée au nom de la base À L'OUVERTURE (`shop` devient `shop@replica`, une
base vide devient `@replica`, un nom qui porte déjà `@replica` n'est pas doublé) : le nom de la base enregistré ne change pas, et
chaque base ouverte depuis l'arbre est lue sur les réplicas. Le choix vit dans le JSON chiffré de la connexion (clé `replica`, écrite
seulement quand elle est vraie : le format d'une connexion sans l'option est inchangé). Une modification qui n'en parle pas la conserve.

Tous les Vitess n'ont pas de réplica : sans tablet réplica en service, vtgate refuse l'ouverture et la connexion échoue comme une cible
injoignable (message générique, jamais un repli silencieux sur le primaire). Le garde et la transaction en lecture seule ne changent pas ;
un `@replica` refuse en plus toute écriture de données (1105). Éprouvé sur `target-vitess` : `tests/Integration/Driver/VitessReplicaTest`
(un seul `mysqld` : l'étiquette de routage est prouvée, pas la réplication).

# Verbes de vtgate et le garde (#40)

Jugés avec le dialecte MySQL, figés par `StatementGuardCorpusTest` et éprouvés sur un vrai vtgate (`VitessShowTest`) :

- `SHOW VITESS_TABLETS|SHARDS|KEYSPACES|REPLICATION_STATUS|MIGRATIONS|TARGET`, `SHOW VITESS_MIGRATION '…' LOGS`, `SHOW VSCHEMA …`, `SHOW KEYSPACES` : lectures du topo, permises (un `SHOW` est une inspection).
- `VEXPLAIN` se juge comme `EXPLAIN` / `EXPLAIN ANALYZE`, jamais refusé en bloc. Mesuré sur vtgate : `PLAN` et `KEYS` n'exécutent rien (une inspection, même d'un UPDATE) ; `QUERIES` et `ALL` jouent la requête (vtgate refuse seul un DML) ; `TRACE` la joue, même une écriture. Pour ces trois formes (et la forme sans mot), la requête expliquée est jugée selon sa nature : lecture permise, écriture refusée en lecture seule puis permise en lecture-écriture avec la confirmation habituelle (DELETE sans WHERE, DROP…). Filet du moteur : dans la transaction en lecture seule, le tablet refuse l'écriture jouée par `TRACE`. Forme illisible (`VEXPLAIN TRACE` seul, requête qui n'est pas un verbe connu) : refus `unsupported_statement`.
- `ALTER VITESS_MIGRATION '…' …` : structure, jamais en lecture seule, confirmation en lecture-écriture. `REVERT VITESS_MIGRATION` : refusé (verbe inconnu du garde). Les DDL en ligne ne sont pas pilotés par TerrilDB.

# Diagnostics et Vitess (#40)

Aucun thème de diagnostic n'est servi sur une connexion Vitess (`ServerDiagnostics::$unsupportedReason = vitess_tablet_scope`, `capabilities` : tout `unsupported`, les routes répondent 501). Mesuré sur `target-vitess` : toutes les sources répondaient, mais vtgate envoie chaque lecture du catalogue à UN tablet, au hasard du routage : les sessions, verrous, comptes, variables ou la réplication d'un seul tablet passeraient pour ceux du cluster (la réplication disait « aucune »). L'interface le dit et renvoie vers une connexion directe sur le tablet voulu, où tous les thèmes se lisent comme sur un MySQL ordinaire. Piste restante : lire tablet par tablet (`SHOW VITESS_TABLETS`, une connexion par tablet).
