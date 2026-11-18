# Réplica jetable : suit repl-mysql-source par GTID (la source est saine avant ce démarrage, voir depends_on).
mysql -uroot -p"$MYSQL_ROOT_PASSWORD" <<SQL
CHANGE REPLICATION SOURCE TO SOURCE_HOST='repl-mysql-source', SOURCE_USER='repl', SOURCE_PASSWORD='$MYSQL_ROOT_PASSWORD', SOURCE_AUTO_POSITION=1, GET_SOURCE_PUBLIC_KEY=1;
START REPLICA;
SQL
