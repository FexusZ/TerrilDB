# Primaire jetable : accepte les connexions de réplication et réserve un slot au réplica (lu par le test d'intégration).
echo "host replication all all scram-sha-256" >> "$PGDATA/pg_hba.conf"
psql -v ON_ERROR_STOP=1 -U postgres -d postgres -c "SELECT pg_create_physical_replication_slot('repl_slot')"
