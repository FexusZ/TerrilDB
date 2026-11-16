import type { PlanFindingInfo, PlanKind, SchemaFinding } from '../api/types'
import { formatCount, formatEstimate } from './format'

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
    case 'foreign_key_type_mismatch':
      return {
        title: `La clé étrangère de « ${finding.table} » sur ${finding.columns.join(', ')} n’a pas le type de la colonne qu’elle référence${finding.detail === null ? '' : ` (${finding.detail})`}.`,
        help: 'Pour comparer les deux colonnes, le moteur convertit celle de cette table sur chaque ligne : son index ne sert plus dans les jointures ni dans les recherches par la clé. Lui donner le type de la colonne référencée règle le problème ; la modification réécrit la colonne, à planifier sur une grosse table.',
      }
  }
}

function lines(count: number): string {
  return count === 1 ? 'ligne' : 'lignes'
}

export function describePlanFinding(finding: PlanFindingInfo): string {
  if (finding.rule === 'stale_estimate') {
    const estimated = finding.rows === null ? 'très peu de lignes' : `${formatEstimate(finding.rows)} ${lines(finding.rows)}`
    const actual = finding.actualRows === undefined || finding.actualRows === null ? 'un autre nombre' : `${formatCount(finding.actualRows)} ${lines(finding.actualRows)}`

    return `Les statistiques de « ${finding.relation ?? 'la table'} » semblent périmées : le moteur estimait ${estimated}, il y en a eu ${actual}.`
  }
  if (finding.rule === 'sort_on_disk') {
    const size = finding.actualRows === undefined || finding.actualRows === null ? '' : ` de ${formatCount(finding.actualRows)} ${lines(finding.actualRows)}`

    return `Un tri${size} a débordé de la mémoire et s’est fait sur disque.`
  }

  const rows = finding.rows === null ? '' : ` (${formatEstimate(finding.rows)} lignes)`
  const condition = finding.condition === null ? '' : `, filtré par ${finding.condition}`

  return `Parcours complet de « ${finding.relation ?? 'la table'} »${rows}${condition}. Un index sur les colonnes filtrées peut l’éviter.`
}

/** Ce qui explique un constat de plan mesuré ; rien pour les autres. */
export function planFindingHelp(finding: PlanFindingInfo): string | null {
  if (finding.rule === 'stale_estimate') {
    return 'Le moteur choisit son plan d’après ses statistiques : les remettre à jour peut le changer. La commande ci-dessous est à relire ; elle n’est jamais exécutée par TerrilDB.'
  }
  if (finding.rule === 'sort_on_disk') {
    return 'Un index sur la colonne de tri évite le tri ; sinon une mémoire de tri plus grande le garde en mémoire (work_mem sur PostgreSQL, sort_buffer_size sur MySQL et MariaDB).'
  }

  return null
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
