#!/bin/sh
# Lit la liste des fichiers modifiés (un par ligne, sur l'entrée standard) et dit quoi vérifier :
#   php=true|false     style, analyse, tests unitaires et d'intégration du back
#   front=true|false   lint, types, tests et build de l'interface
#   deploy=true|false  de quoi justifier un déploiement (tout sauf la documentation seule)
# Dans le doute (fichier inconnu, liste vide), tout est vérifié : on ne saute jamais un contrôle par erreur.
php=false
front=false
seen=false
while IFS= read -r file; do
  [ -n "$file" ] || continue
  seen=true
  case "$file" in
    *.md | docs/* | LICENSE | .gitignore | .gitattributes) ;;
    assets/* | package.json | package-lock.json | vite.config.* | tsconfig*.json | eslint.config.*) front=true ;;
    src/* | tests/* | config/* | migrations/* | templates/* | bin/* | public/* | composer.json | composer.lock | symfony.lock | phpunit.dist.xml | phpstan* | .php-cs-fixer*) php=true ;;
    *) php=true; front=true ;;
  esac
done
if [ "$seen" = false ]; then
  php=true
  front=true
fi
deploy=false
if [ "$php" = true ] || [ "$front" = true ]; then
  deploy=true
fi
echo "php=$php"
echo "front=$front"
echo "deploy=$deploy"
