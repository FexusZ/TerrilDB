#!/bin/sh
# Essais de changes.sh : chaque cas donne les fichiers modifiés et le résultat attendu « php front deploy ».
here=$(dirname "$0")
failed=0
check() {
  expected="$1"
  shift
  actual=$(printf '%s\n' "$@" | sh "$here/changes.sh" | sed 's/^[a-z]*=//' | tr '\n' ' ' | sed 's/ $//')
  if [ "$actual" != "$expected" ]; then
    echo "ECHEC : [$*] attendu « $expected », obtenu « $actual »"
    failed=1
  fi
}
check "false false false" CLAUDE.md docs/deploy.md
check "false true true" assets/views/TableDataPane.vue assets/utils/sql.ts
check "false true true" package-lock.json
check "true false true" src/Core/Data/DataChangePlanner.php tests/Core/Data/DataChangePlannerTest.php
check "true false true" composer.lock migrations/Version20261106030000.php
check "true true true" src/Core/Data/DataChangePlanner.php assets/utils/sql.ts
check "true true true" .github/workflows/ci.yml
check "true true true" docker/php/Dockerfile
check "true true true" compose.yaml
check "true true true" un-fichier-inconnu.xyz
check "false true true" assets/main.ts README.md
check "true true true"
if [ "$failed" -eq 0 ]; then
  echo "changes.sh : tous les cas passent"
fi
exit "$failed"
