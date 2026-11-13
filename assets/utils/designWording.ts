import type { DesignRule, SchemaFinding } from '../api/types'
import type { Wording } from './optimizer'

/** Les mots des règles de conception de l'audit (le reste est dans `optimizer.ts`). */
export function describeDesignFinding(finding: SchemaFinding & { rule: DesignRule }): Wording {
  switch (finding.rule) {
    case 'legacy_character_set':
      return {
        title: `« ${finding.table} » garde du texte en utf8 sur trois octets : ${finding.columns.join(', ')}.`,
        help: 'Ce jeu de caractères refuse les émojis et une partie des idéogrammes (erreur 1366), et son nom « utf8 » est déprécié. utf8mb4 le remplace ; la conversion réécrit la table et peut dépasser la longueur maximale d’un index : à essayer sur une copie.',
      }
    case 'implicit_foreign_key':
      return {
        title: `« ${finding.table} » : ${finding.columns.join(', ')} semble désigner ${finding.detail ?? 'une autre table'}, mais aucune clé étrangère ne le dit.`,
        help: 'Sans clé étrangère, rien n’empêche une ligne orpheline ni une suppression qui laisse des références dans le vide. À vérifier : seul le nom et le type concordent. Comptez d’abord les lignes orphelines (LEFT JOIN … WHERE … IS NULL) : la clé ne s’ajoutera pas tant qu’il en reste.',
      }
    case 'collation_mismatch':
      return {
        title: `« ${finding.table} » : ${finding.columns.join(', ')} et la colonne qu’elle désigne n’ont pas la même collation${finding.detail === null ? '' : ` (${finding.detail})`}.`,
        help: 'Une jointure entre les deux échoue (erreur 1267) ou, si le jeu de caractères diffère aussi, convertit l’une des colonnes et n’utilise plus son index. Aucun SQL n’est proposé : changer la collation redit toute la définition de la colonne, à faire à la main.',
      }
    case 'twin_table':
      return {
        title: `« ${finding.table} » a exactement les colonnes de « ${finding.detail ?? 'sa voisine'} » : reste de sauvegarde ?`,
        help: 'À vérifier : une table de copie oubliée occupe de la place et finit par être prise pour la vraie. Si elle ne sert plus, on la supprime ; TerrilDB ne propose aucune suppression.',
      }
    case 'copy_without_indexes':
      return {
        title: `« ${finding.table} » n’a aucun index, alors que « ${finding.detail ?? 'sa voisine'} » en a.`,
        help: 'Une copie faite par CREATE TABLE … AS SELECT recopie les colonnes mais ni la clé primaire, ni les index, ni l’auto-incrément. Si la copie sert encore, recréez ce qui lui manque.',
      }
    case 'timestamp_deadline':
      return {
        title: `« ${finding.table} » : ${finding.columns.join(', ')} est un TIMESTAMP, et son nom dit une échéance.`,
        help: 'À vérifier : sur MySQL un TIMESTAMP s’arrête au 19 janvier 2038 et refuse une date plus lointaine (un abonnement de vingt ans, une échéance de contrat). DATETIME va jusqu’à l’an 9999. Si les dates restent proches, il n’y a rien à faire. Changer le type réécrit la colonne : à planifier.',
      }
    case 'date_as_text':
      return {
        title: `« ${finding.table} » : ${finding.columns.join(', ')} est du texte${finding.detail === null ? '' : ` (${finding.detail})`}, et son nom dit une date.`,
        help: 'À vérifier : dans une colonne de texte le moteur ne contrôle plus la forme (« 31/02 » passe), trie par ordre alphabétique et ne sait plus calculer entre deux dates. Un type DATE ou DATETIME règle cela ; la conversion dépend du format réellement stocké : essayez-la sur une copie.',
      }
  }
}
