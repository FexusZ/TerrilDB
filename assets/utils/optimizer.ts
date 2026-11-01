import type { PlanFindingInfo, PlanKind, SchemaFinding } from '../api/types'
import { formatEstimate } from './format'

export interface Wording {
  title: string
  help: string
}

export function describeSchemaFinding(finding: SchemaFinding): Wording {
  switch (finding.rule) {
    case 'missing_primary_key':
      return {
        title: `La table « ${finding.table} » n’a pas de clé primaire.`,
        help: 'Sans clé primaire, on ne peut pas identifier une ligne de façon sûre : modification ciblée, réplication et import deviennent fragiles.',
      }
    case 'foreign_key_without_index':
      return {
        title: `La clé étrangère de « ${finding.table} » sur ${finding.columns.join(', ')} n’a pas d’index.`,
        help: 'Les jointures et les suppressions en cascade sur cette clé parcourent toute la table. Un index les rend directes.',
      }
    case 'redundant_index':
      return {
        title: `L’index « ${finding.index ?? ''} » de « ${finding.table} » est inutile : « ${finding.coveredBy ?? ''} » le couvre déjà.`,
        help: 'Il ralentit les écritures et occupe de la place sans rien apporter aux lectures. Vérifiez qu’aucune requête ne le désigne explicitement avant de le supprimer.',
      }
  }
}

export function describePlanFinding(finding: PlanFindingInfo): string {
  const rows = finding.rows === null ? '' : ` (${formatEstimate(finding.rows)} lignes)`
  const condition = finding.condition === null ? '' : `, filtré par ${finding.condition}`

  return `Parcours complet de « ${finding.relation ?? 'la table'} »${rows}${condition}. Un index sur les colonnes filtrées peut l’éviter.`
}

const KINDS: Record<Exclude<PlanKind, 'other'>, string> = {
  full_scan: 'Parcours complet',
  index_full_scan: 'Parcours d’index complet',
  index_scan: 'Recherche par index',
  join: 'Jointure',
  sort: 'Tri',
  aggregate: 'Agrégation',
  limit: 'Limite',
}

/** Le mot français d'une opération ; ce que le moteur a écrit quand on n'en a pas. */
export function planKindLabel(kind: PlanKind, label: string): string {
  return kind === 'other' ? label : KINDS[kind]
}
