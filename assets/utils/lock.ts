/**
 * Pourquoi l'éditeur de structure ne redéfinit pas une colonne ou un index : le moteur lui donne une particularité que TerrilDB ne
 * sait pas redire (la redéfinir la ferait disparaître en silence). Les codes viennent du serveur (`LockReason`).
 */
const LABELS: Record<string, { short: string; sentence: string }> = {
  generated: { short: 'colonne générée', sentence: 'cette colonne est générée' },
  column_check: { short: 'colonne avec CHECK', sentence: 'cette colonne porte une contrainte CHECK' },
  index_invisible: { short: 'index invisible', sentence: 'cet index est invisible' },
  index_ignored: { short: 'index ignoré', sentence: 'cet index est ignoré (IGNORED)' },
  fulltext: { short: 'index FULLTEXT', sentence: 'cet index est de type FULLTEXT' },
  spatial: { short: 'index spatial', sentence: 'cet index est spatial' },
  prefix: { short: 'index à préfixe', sentence: 'cet index porte une longueur de préfixe' },
  descending: { short: 'index décroissant', sentence: 'cet index a un ordre décroissant' },
  expression: { short: 'index sur expression', sentence: 'cet index porte une expression' },
}

export function lockShort(reason: string): string {
  return LABELS[reason]?.short ?? 'définition non prise en charge'
}

export function lockSentence(reason: unknown): string | null {
  return typeof reason === 'string' ? (LABELS[reason]?.sentence ?? null) : null
}
