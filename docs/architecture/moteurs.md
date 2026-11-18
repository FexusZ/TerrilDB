# Moteurs : ClickHouse, Redis, différences MySQL / MariaDB

Détail déplacé de `CLAUDE.md` sans modification de fond ; les règles essentielles restent dans `CLAUDE.md`.

## ClickHouse (src/Driver/ClickHouse)

Pas de pilote DBAL pour ClickHouse : `ClickHouseHttp` parle son interface HTTP (`symfony/http-client`), port 8123 par défaut
(8443 et 443 = TLS, déduit du port faute d'option dédiée). L'instruction part dans le corps d'un POST, les réglages et les
paramètres liés (`{nom:Type}`) dans l'adresse, les identifiants dans les en-têtes `X-ClickHouse-User/Key` (jamais dans une
URL). Réponse `JSONCompact` ; `JSON_BIGINT_AS_STRING` garde les UInt64 démesurés en texte. Erreurs normalisées
(`ClickHouseException` : code du moteur, message nettoyé du « Code: … (version …) », jamais d'adresse).
`ClickHouseQueryRunner` : lecture seule = réglage `readonly=1` imposé par le moteur (refuse écritures, DDL, `SETTINGS`,
fonctions de table sortantes comme `url()`/`remote()`) ; délai = `max_execution_time` (secondes fractionnaires, code 159 ->
`QueryTimeoutException`) ; limite de lignes = `max_result_rows=maxRows+1` + `result_overflow_mode=break` + `max_block_size`
ramené à la limite, le surplus est écarté ici. Pas de transaction : une écriture est immédiate. Les fonctions de table qui
sortent du serveur (`file`, `url`, `s3`, `remote`, `mysql`…) sont refusées par le garde seulement quand elles sont APPELÉES
(`calls()` du scanner), et la clause `FORMAT` aussi (le runner impose son format). Le dialecte du scanner (`SqlDialect::ClickHouse`) :
antislash dans chaînes ET identifiants, `--` sans espace, `#` commentaire, pas de commentaire exécutable, guillemets dollar ;
les commentaires `/* */` sont lus sans imbrication (ClickHouse les imbrique : la lecture est plus stricte, jamais plus laxiste).
`ClickHouseIntrospector` : `system.tables/columns/data_skipping_indices` ; pas de clé étrangère, et la clé de tri est un index
non unique nommé « ORDER BY » (« PRIMARY KEY » si différente), jamais une clé primaire relationnelle ; lignes = `total_rows`.
Plan d'exécution par `EXPLAIN json=1, indexes=1` lu par `ClickHousePlanParser` (ni coût ni lignes dans ce format : `PlanAnalyzer` ne
signale donc rien ; une lecture dont la clé de tri ne restreint rien est un parcours complet, sinon « PRIMARY KEY » + condition) ; pas de
règle d'audit (relationnelles). Redis garde le 501 `plan_unavailable` (bouton masqué par `ENGINE_HAS_PLAN`). Cible de test `target-clickhouse` (`TARGET_CLICKHOUSE_URL`, port hôte 18123) dans le profil `targets`.

## Redis (src/Driver/Redis, src/Core/KeyValue, src/Core/Guard/Redis*)

Pas un moteur SQL (`Engine::speaksSql()` est faux) : une commande Redis est une « instruction » du même pipeline (garde ->
exécuteur -> audit), donc console, API à jetons et MCP fonctionnent sans plomberie propre ; seul le schéma de tables manque
(introspecteur absent : /tables, /diagram, /audit, /plan rendent 501). Client `predis/predis` (pur PHP, l'image n'a pas
ext-redis) ; PHPStan veut `--memory-limit=512M`. Paramètres de connexion : « base » = numéro de base (0-255, vérifié à
l'ouverture), « identifiant » = utilisateur ACL (`default` sans ACL), port 6380 = TLS. `RedisCommandLine` découpe le texte
comme redis-cli (guillemets, `\xHH`, une commande par ligne) : le garde juge les ARGUMENTS qui partiront, pas le texte.
`RedisCommandGuard` = LISTE BLANCHE (lectures, puis écritures en mode lecture-écriture) ; refus nets : KEYS (SCAN le remplace),
commandes bloquantes ou de flux (dont XREAD : le serveur le classe bloquant), scripts, modules, CONFIG/ACL/CLIENT/DEBUG,
réplication, AUTH, FLUSHALL ; FLUSHDB demande confirmation. `RedisQueryRunner` : avant d'envoyer en lecture seule, interroge
`COMMAND INFO commande` et `commande|sous-commande` et refuse ce que le SERVEUR marque write/admin/blocking/pubsub (le serveur
fait foi ; un test vérifie que toute lecture de la liste blanche passe ce contrôle). Un client Predis NEUF par commande :
le délai de lecture (`read_write_timeout`) n'est pas modifiable sur une connexion ouverte ; `Predis\TimeoutException` ->
`QueryTimeoutException`. Réponse -> tableau (`value` ; HGETALL = field/value ; INFO = section/key/value ; imbriqué = JSON).
`RedisKeyValueStore` : SCAN (jamais KEYS, tranches <= 1000), TYPE + TTL en pipeline, lectures bornées par type (HSCAN/SSCAN,
LRANGE, ZRANGE, XRANGE COUNT, GETRANGE 64 Kio), noms de clés non UTF-8 laissés de côté. Web : `GET /web/connections/{id}/keys`
(`pattern`, `cursor`, `count` <= 1000) et `GET .../key?name=&limit=` (nom en paramètre de requête : il peut contenir `/`, `?`,
de l'unicode). Front : `KeyPane` (les clés se parcourent dans l'arbre de la barre latérale), la console réutilise `QueryPane` (pas de plan : `ENGINE_HAS_PLAN`).
Cible de test `target-redis` (`TARGET_REDIS_URL`, base 15, hôte 63791) : `RedisTarget::seeded()` vide et remplit la base 15
(refuse toute autre base).

## Différences MySQL / MariaDB mesurées (#86, tests/Integration/Driver/MySqlFamily*Test)

Rejouées sur les quatre cibles (MySQL 8.4 et 26, MariaDB 11.8 et 13), objets jetables retirés en `tearDown`. Socle : trait `MySqlFamilyTarget`.
Largeur d'affichage : MariaDB rend `int(11)`, `smallint(5)`, `bigint(20)`, MySQL 8.0.19+ `int`, `smallint`, `bigint` (`tinyint(1)` et `zerofill` gardés, `timestamp(n)`/`time(n)` toujours) ;
l'empreinte de structure est stable d'une lecture à l'autre et après un `MODIFY COLUMN` d'une autre colonne (rien n'est corrigé : pas de défaut). Collations : défaut du serveur
`utf8mb4_0900_ai_ci` (MySQL) contre `utf8mb4_uca1400_ai_ci` (MariaDB) ; `MODIFY COLUMN` remet jeu de caractères ET collation d'une colonne texte à ceux de la table (une colonne latin1 ou
`utf8mb4_bin` les perd : c'est le comportement documenté de l'éditeur, verrouillé). `SHOW` : aucun SHOW du code de production n'est une forme renommée (test qui les relit dans `src/` et les
exécute) ; `SHOW MASTER STATUS` et `SHOW SLAVE STATUS` n'existent plus en MySQL 8.4/26, MariaDB les accepte avec les nouveaux noms : seuls `BINARY LOG STATUS` et `REPLICA STATUS` sont
communs, et `SHOW PROCESSLIST` a une colonne `Progress` en plus chez MariaDB. `performance_schema` est éteint par défaut chez MariaDB (`sys` y existe pourtant) : requêtes coûteuses,
usage des index et pré-contrôle des verrous de table rendent `disabled` / `performance_schema_off`, sessions, transactions, variables et santé se lisent par une autre source. Délais :
lecture coupée au délai demandé sur les quatre (250 ms, 1 s) ; une ÉCRITURE n'est coupée que par MariaDB (annulée), MySQL va au bout. Cache de statistiques : MySQL 8 garde
`TABLES`/`STATISTICS` 24 h (`information_schema_stats_expiry`) et rendait un nombre de lignes d'avant (0 après un chargement de 2000 lignes, vérifié) : `DbalConnector` pose `0` pour la
session MySQL (coût mesuré : environ 3 ms de plus pour deux pages de cent tables) ; MariaDB n'a pas la variable. Les tailles (`DATA_LENGTH`, `INDEX_LENGTH`) retardent aussi sur les
statistiques InnoDB persistantes (échantillonnées, jusqu'à `ANALYZE TABLE`), indépendamment du cache : ce sont des estimations. Session : `utf8mb4` de bout en bout (émoji et ZWJ
intacts, octets identiques) ; le fuseau de session est celui du serveur (`SYSTEM`) et n'est pas fixé (TIMESTAMP suit le fuseau, DATETIME non) ; `sql_mode` = celui du serveur moins
`NO_BACKSLASH_ESCAPES` et `ANSI_QUOTES` (mode strict gardé : valeur invalide refusée) ; dates zéro refusées par MySQL (`NO_ZERO_DATE`), acceptées par MariaDB. Noms exotiques : tiret,
espace, point, backtick, accents, CJK, `Tx`/`tx`/`TX` distincts (`lower_case_table_names = 0` sur les cibles : une valeur 1 ou 2 n'est pas testable ici), pagination par `BINARY TABLE_NAME`,
recherche, éditeur de structure (renommage de colonne, ajout) et export CSV/JSON tiennent ; `#mysql50#…` est accepté par MySQL (base et table) et refusé par MariaDB (1102/1103) ;
les noms sont stockés en utf8mb3 : MariaDB refuse un émoji, MySQL l'accepte et le range en `?` (inadressable), et comparer un nom ou une recherche sur 4 octets à une colonne utf8mb3
faisait échouer la requête (500) : `describeTable` rend `TableNotFound`, la recherche rend une liste vide. Tables système de MariaDB en Aria : toutes se décrivent, taille d'index nulle.
