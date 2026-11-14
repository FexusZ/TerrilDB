#!/bin/sh
# Accorde le délai du reverse proxy (nginx) et TERRILDB_PROXY_TIMEOUT, la valeur dont l'application tire sa limite de durée des requêtes.
#
#   sh docker/prod/proxy-timeout.sh check <hôte-virtuel> <secondes>   0 = d'accord, 1 = nginx diffère, 2 = illisible ou introuvable (rien à dire)
#   sh docker/prod/proxy-timeout.sh apply <hôte-virtuel> <secondes>   réécrit proxy_read_timeout et l'en-tête X-TerrilDB-Proxy-Timeout (sudo si besoin)
#
# 64 = mauvais usage ou valeur que l'application refuserait (30 à 3600 secondes).
set -eu

cmd="${1:-}"
file="${2:-}"
wanted="${3:-}"

usage() {
    echo "Usage : proxy-timeout.sh check|apply <hôte-virtuel> <secondes (30 à 3600)>" >&2
    exit 64
}

case "$cmd" in check | apply) ;; *) usage ;; esac
[ -n "$file" ] || usage
case "$wanted" in '' | *[!0-9]*) usage ;; esac
{ [ "$wanted" -ge 30 ] && [ "$wanted" -le 3600 ]; } || usage

[ -r "$file" ] || { echo "Hôte virtuel nginx introuvable ou illisible : $file (contrôle ignoré)." >&2; exit 2; }

# La valeur de proxy_read_timeout en secondes (60 si la directive manque : c'est le défaut de nginx). Vide = illisible.
current_seconds() {
    value="$(sed -n 's/^[[:space:]]*proxy_read_timeout[[:space:]]\{1,\}\([^;[:space:]]*\)[[:space:]]*;.*/\1/p' "$file" | head -n 1)"
    [ -n "$value" ] || { echo 60; return 0; }
    number="${value%[smh]}"
    unit="${value#"$number"}"
    case "$number" in '' | *[!0-9]*) return 1 ;; esac
    case "$unit" in
        '' | s) echo "$number" ;;
        m) echo $((number * 60)) ;;
        h) echo $((number * 3600)) ;;
        *) return 1 ;;
    esac
}

if [ "$cmd" = check ]; then
    if ! actual="$(current_seconds)"; then
        echo "Le proxy_read_timeout de $file n'est pas lisible : accord non vérifié." >&2
        exit 2
    fi
    if [ "$actual" -eq "$wanted" ]; then
        echo "nginx et l'application sont d'accord : ${wanted} s."
        exit 0
    fi
    echo "nginx coupe les réponses après ${actual} s alors que TERRILDB_PROXY_TIMEOUT vaut ${wanted} s : la limite de durée des requêtes ne correspond pas." >&2
    echo "Accordez-les : sh docker/prod/sync-nginx.sh (ou corrigez TERRILDB_PROXY_TIMEOUT dans deploy.env)." >&2
    exit 1
fi

# apply : directive et en-tête réécrits ensemble, le reste du site n'est pas touché.
grep -q '^[[:space:]]*proxy_read_timeout' "$file" || { echo "Aucune directive proxy_read_timeout dans $file : rien modifié." >&2; exit 2; }
tmp="$(mktemp)"
trap 'rm -f "$tmp"' EXIT
awk -v t="$wanted" '
    /^[ \t]*proxy_set_header[ \t]+X-TerrilDB-Proxy-Timeout/ { next }
    /^[ \t]*proxy_read_timeout/ {
        match($0, /^[ \t]*/)
        indent = substr($0, 1, RLENGTH)
        print indent "proxy_read_timeout " t "s;"
        print indent "proxy_set_header   X-TerrilDB-Proxy-Timeout " t ";"
        next
    }
    { print }
' "$file" > "$tmp"
cat "$tmp" > "$file"
echo "$file : proxy_read_timeout ${wanted}s et en-tête X-TerrilDB-Proxy-Timeout alignés."
