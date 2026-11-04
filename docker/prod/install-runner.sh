#!/bin/sh
# Installe et enregistre un runner GitHub Actions DÉDIÉ à ce dépôt (étiquette « terrildb ») dans $HOME. Sans droits root.
# Avec --service, installe aussi le service systemd (sudo) pour qu'il tourne en permanence.
# Le jeton d'enregistrement vient de `gh` (compte administrateur du dépôt) ; il expire en une heure et n'est jamais affiché.
set -eu

command -v gh >/dev/null || { echo "gh est requis (compte administrateur du dépôt)." >&2; exit 2; }
repo="$(gh repo view --json nameWithOwner --jq .nameWithOwner)"
dir="${TERRILDB_RUNNER_DIR:-$HOME/actions-runner-terrildb}"
[ "$(uname -m)" = "x86_64" ] || { echo "Seul x86_64 est géré par ce script." >&2; exit 2; }

if [ ! -e "$dir/.runner" ]; then
    version="$(gh api repos/actions/runner/releases/latest --jq .tag_name | sed 's/^v//')"
    expected="$(gh api repos/actions/runner/releases/latest --jq .body | grep -o 'BEGIN SHA linux-x64 -->[0-9a-f]*' | head -1 | sed 's/.*-->//')"
    [ -n "$version" ] && [ -n "$expected" ] || { echo "Version ou somme de contrôle du runner introuvable." >&2; exit 1; }

    mkdir -p "$dir"
    archive="$dir/runner.tar.gz"
    curl -fsSL -o "$archive" "https://github.com/actions/runner/releases/download/v$version/actions-runner-linux-x64-$version.tar.gz"
    echo "$expected  $archive" | sha256sum -c - >/dev/null || { rm -f "$archive"; echo "Somme de contrôle du runner incorrecte." >&2; exit 1; }
    tar xzf "$archive" -C "$dir"
    rm -f "$archive"

    token="$(gh api -X POST "repos/$repo/actions/runners/registration-token" --jq .token)"
    "$dir/config.sh" --unattended --url "https://github.com/$repo" --token "$token" \
        --name terrildb-runner --labels terrildb --work _work --replace
    echo "Runner enregistré dans $dir (étiquette terrildb)."
else
    echo "Runner déjà enregistré dans $dir."
fi

if [ "${1:-}" = "--service" ]; then
    cd "$dir"
    sudo ./svc.sh install "$(id -un)"
    sudo ./svc.sh start
    sudo ./svc.sh status | head -5
fi
