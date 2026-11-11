#!/bin/sh
# Réchauffe le cache avec l'environnement réel (APP_SECRET, DATABASE_URL…) puis sert l'application.
set -eu
# Hôtes acceptés (SYMFONY_TRUSTED_HOSTS) : dérivés de TERRILDB_ALLOWED_HOSTS, donc du domaine configuré hors dépôt.
SYMFONY_TRUSTED_HOSTS="$(sh /usr/local/bin/terrildb-trusted-hosts "${TERRILDB_ALLOWED_HOSTS:-}")"
export SYMFONY_TRUSTED_HOSTS
php bin/console cache:warmup --no-debug
exec frankenphp run --config /etc/frankenphp/Caddyfile --adapter caddyfile
