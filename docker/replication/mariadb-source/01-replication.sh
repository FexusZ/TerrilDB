# Source jetable : un compte de réplication (même mot de passe que root, la cible est jetable) et une table que le réplica reçoit.
mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" <<SQL
CREATE USER 'repl'@'%' IDENTIFIED BY '$MARIADB_ROOT_PASSWORD';
GRANT REPLICATION SLAVE ON *.* TO 'repl'@'%';
CREATE DATABASE d;
CREATE TABLE d.t (id INT PRIMARY KEY, label VARCHAR(20));
INSERT INTO d.t VALUES (1, 'one'), (2, 'two');
SQL
