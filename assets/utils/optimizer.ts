import type { OperationsCheck, PlanFindingInfo, PlanKind, SchemaFinding } from '../api/types'
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
    case 'foreign_key_type_mismatch':
      return {
        title: `La clé étrangère de « ${finding.table} » sur ${finding.columns.join(', ')} n’a pas le type de la colonne qu’elle référence${finding.detail === null ? '' : ` (${finding.detail})`}.`,
        help: 'Pour comparer les deux colonnes, le moteur convertit celle de cette table sur chaque ligne : son index ne sert plus dans les jointures ni dans les recherches par la clé. Lui donner le type de la colonne référencée règle le problème ; la modification réécrit la colonne, à planifier sur une grosse table.',
      }
    case 'stale_statistics':
      return {
        title: `Les statistiques de « ${finding.table} » sont périmées${finding.detail === null ? '' : ` (${finding.detail})`}.`,
        help: 'L’optimiseur choisit ses plans d’après des statistiques sur le contenu de la table ; périmées, elles peuvent lui faire préférer un mauvais plan. ANALYZE les recalcule sur un échantillon, sans bloquer les écritures, mais il peut changer des plans de requêtes : à lancer hors pointe, en connaissance de cause. L’autovacuum s’en charge d’ordinaire : s’il est en retard, vérifiez qu’il n’est pas désactivé ni retenu par une transaction longue.',
      }
    case 'dead_tuples':
      return {
        title: `La table « ${finding.table} » garde beaucoup de lignes mortes${finding.detail === null ? '' : ` (${finding.detail})`}.`,
        help: 'Les lignes supprimées ou modifiées restent dans le fichier de la table jusqu’au nettoyage. VACUUM les rend réutilisables sans bloquer les lectures ni les écritures, mais la taille du fichier ne diminue en général pas. VACUUM FULL la réduit, au prix d’un verrou exclusif sur la table pendant toute la réécriture. Les chiffres sont des estimations du collecteur de statistiques de PostgreSQL.',
      }
    case 'reclaimable_space':
      return {
        title: `La table « ${finding.table} » a de la place libre à récupérer${finding.detail === null ? '' : ` (${finding.detail})`}.`,
        help: 'Cette place est dans le fichier de la table : elle servira aux prochaines écritures mais n’est pas rendue au disque. OPTIMIZE TABLE reconstruit la table pour la rendre : il lit et réécrit toutes les données, demande de l’espace temporaire égal à la table et beaucoup d’E/S, avec de brèves attentes de verrou. Rien d’urgent : une table qui doit regrossir bientôt n’a rien à y gagner.',
      }
    case 'orphan_definer':
      return {
        title: `« ${finding.index ?? finding.table} » : ${finding.detail ?? 'créateur inexistant'}.`,
        help: 'Cet objet s’exécute sous l’identité de son créateur, et ce compte n’existe plus (base restaurée sur un autre serveur, compte supprimé). Son usage échoue avec l’erreur 1449 (« The user specified as a definer does not exist »), qui ne nomme pas toujours l’objet en cause. Recréez-le avec un compte qui existe : relisez sa définition d’abord, rien n’est modifié sans vous.',
      }
  }
}

const OPERATIONS_RULES: Record<OperationsCheck['rule'], string> = {
  stale_statistics: 'Statistiques périmées',
  dead_tuples: 'Tuples morts',
  reclaimable_space: 'Place libre',
  orphan_definer: 'Créateurs d’objets',
}

/** Ce qu'une règle d'exploitation n'a pas pu vérifier, et pourquoi. */
export function describeOperationsCheck(check: OperationsCheck): string {
  const reason = check.reason === 'permission_denied' ? 'le compte n’a pas le droit de lire ces informations' : 'ces informations n’ont pas pu être lues'

  return `${OPERATIONS_RULES[check.rule]} : non vérifiable, ${reason}.`
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
