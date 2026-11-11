#!/bin/sh
# Rend le motif de SYMFONY_TRUSTED_HOSTS pour une liste de noms séparés par des virgules (TERRILDB_ALLOWED_HOSTS) :
# ces noms, plus localhost et 127.0.0.1 (le contrôle de santé du conteneur). Aucun nom n'est écrit dans le dépôt.
set -eu
names="localhost,127.0.0.1,${1:-}"
escaped="$(printf '%s' "$names" | tr -d ' ' | sed -e 's/[][\\.^$*+?(){}|]/\\&/g' -e 's/,\{1,\}/|/g' -e 's/^|//' -e 's/|$//')"
printf '^(%s)$\n' "$escaped"
