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
  }
}
