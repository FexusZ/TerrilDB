# Réplica jetable : suit repl-mariadb-source par GTID.
mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" <<SQL
CHANGE MASTER TO MASTER_HOST='repl-mariadb-source', MASTER_USER='repl', MASTER_PASSWORD='$MARIADB_ROOT_PASSWORD', MASTER_USE_GTID=slave_pos;
START SLAVE;
SQL
