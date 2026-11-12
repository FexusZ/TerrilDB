# Cible de test Vitess

Un seul conteneur (`target-vitess`, profil `vitess`) fait tourner `vttestserver` : un keyspace `shop` non partagé avec des tablets
**primaire**, **réplica** et **lecture seule**, plus un vtgate qui parle le protocole MySQL. Le jeu de données est celui des autres cibles
(`docker/targets/mysql`, 7 000 lignes dans `order_items`).

**Limite importante** : `vttestserver` (vtcombo) n'a qu'UN `mysqld` pour toute la partition. Les tablets réplica et lecture seule
sont des étiquettes de routage sur le même serveur : il n'y a pas de réplication réelle, pas de retard, et `@@read_only` y vaut 0 parce
que c'est le primaire qui répond. Cette cible ne permet donc PAS de tester la lecture seule d'un réplica (#39) ni la réplication.
Pour cela il faut un vrai cluster (un `mysqld` par tablet, par exemple `vtorc`/`vttablet` en conteneurs séparés), à construire à part.

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
