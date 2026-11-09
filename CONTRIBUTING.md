# Contribuer à TerrilDB

Merci de l'intérêt porté à TerrilDB.

## Ce qui est bienvenu aujourd'hui

- **Les issues** : bugs, idées, questions, retours d'usage. Décrivez le moteur de base de données (MySQL, MariaDB,
  PostgreSQL, ClickHouse, Redis) et les étapes pour reproduire.
- **Ne publiez jamais** d'hôte, de mot de passe, de jeton, de phrase de récupération ni de donnée réelle dans une
  issue : remplacez-les par un exemple inventé.
- **Une faille de sécurité** ne se décrit pas dans une issue publique : ouvrez une issue qui dit seulement qu'une faille
  existe (sans détail technique) pour convenir d'un canal privé.

## Les pull requests de code : pas encore

TerrilDB est publié sous [licence AGPL-3.0](LICENSE). Pour garder la possibilité de faire évoluer la licence du projet à
l'avenir, il faut des droits clairs sur chaque ligne de code : **les pull requests de code (y compris les corrections
mineures) ne sont pas acceptées pour l'instant**. Un accord de contribution sera mis en place avant de les ouvrir.

Si vous voulez proposer une amélioration, ouvrez une issue qui la décrit : elle sera lue, et vous pourrez être prévenu
quand les contributions seront ouvertes. Cette règle ne concerne pas les mises à jour automatiques de dépendances.

## Pour plus tard : les règles du projet

Quand les contributions ouvriront, elles suivront les mêmes règles que le reste du code : tests écrits d'abord (TDD),
analyse statique PHPStan au niveau maximal, style PHP-CS-Fixer, ESLint et Vitest côté interface, un commit par lot
cohérent. Les commandes sont décrites dans le [README](README.md).
