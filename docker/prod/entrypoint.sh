#!/bin/sh
# Réchauffe le cache avec l'environnement réel (APP_SECRET, DATABASE_URL…) puis sert l'application.
set -eu
php bin/console cache:warmup --no-debug
exec frankenphp run --config /etc/frankenphp/Caddyfile --adapter caddyfile
