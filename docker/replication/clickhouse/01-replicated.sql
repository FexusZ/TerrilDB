CREATE DATABASE d;
CREATE TABLE d.rt (id UInt64, label String)
ENGINE = ReplicatedMergeTree('/clickhouse/tables/{shard}/d/rt', '{replica}')
ORDER BY id;
INSERT INTO d.rt VALUES (1, 'one'), (2, 'two');
