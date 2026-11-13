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
  }
}
