# Cible de test Vitess

Un seul conteneur (`target-vitess`, profil `vitess`) fait tourner `vttestserver` : un keyspace `shop` non partagé avec un tablet
**primaire** et un tablet **réplica** (vrais `mysqld`, réplication réelle), plus un vtgate qui parle le protocole MySQL.
Le jeu de données est celui des autres cibles (`docker/targets/mysql`, 7 000 lignes dans `order_items`).

```bash
sh docker/init-env.sh                                       # ajoute TARGET_VITESS_URL à docker/dev/php.env
docker compose --profile vitess up -d --wait target-vitess  # ~1,5 Go de mémoire
docker compose --profile vitess down                        # arrêt (les données sont jetables)
```

- Accès depuis l'hôte : `127.0.0.1:33577`, utilisateur `root`, sans mot de passe. Depuis le conteneur `php` : `TARGET_VITESS_URL`
  (`pdo-mysql://root@target-vitess:33577/shop`).
- Version annoncée : `8.0.35-Vitess` (`@@version` se termine par `-Vitess`, c'est ce que le driver doit détecter).
- Lecture sur le réplica : `USE \`shop@replica\`` (ou `shop@primary`, `shop@rdonly`). `SHOW VITESS_TABLETS` liste les tablets.
- Hors profil `targets` et hors CI : plus lourd et plus lent à démarrer que les autres cibles.

## Constats déjà vérifiés sur cette cible

- #38 : `SET SESSION MAX_EXECUTION_TIME` est accepté mais pas transmis (`@@session.max_execution_time` reste 0, `SELECT SLEEP(2)` n'est
  pas coupé) ; `/*vt+ QUERY_TIMEOUT_MS=1000 */` coupe bien (erreur 3024).
- #39 : sur le tablet réplica, `@@read_only` vaut 0 : le moteur n'impose pas la lecture seule, seul le filtre protège.
- Une écriture faite sur le primaire est visible sur le réplica au bout d'un instant.
