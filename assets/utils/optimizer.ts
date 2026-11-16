import type { AuditCheck, PlanFindingInfo, PlanKind, SchemaFinding } from '../api/types'
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
    case 'text_primary_key':
      return {
        title: `La clé primaire de « ${finding.table} » (${finding.columns.join(', ')}) est un UUID rangé en texte${finding.detail === null ? '' : ` (${finding.detail})`}.`,
        help: 'À vérifier : si ces valeurs sont bien des UUID, le texte occupe 36 octets au lieu de 16, et InnoDB recopie la clé primaire dans chaque index secondaire. Le type natif (uuid sur PostgreSQL, BINARY(16) avec UUID_TO_BIN sur MySQL et MariaDB) allège la table et ses index ; la conversion réécrit la colonne et les clés étrangères qui la référencent, à planifier.',
      }
    case 'float_amount':
      return {
        title: `La colonne « ${finding.columns.join(', ')} » de « ${finding.table} » est un nombre à virgule flottante${finding.detail === null ? '' : ` (${finding.detail})`} alors que son nom évoque une somme d’argent.`,
        help: 'À vérifier, d’après le nom seul : un flottant binaire n’écrit pas exactement 0,10 et les écarts d’arrondi s’accumulent dans les sommes et les comparaisons. Pour de l’argent, DECIMAL (numeric sur PostgreSQL) est exact ; la conversion réécrit la colonne, à planifier sur une grosse table.',
      }
    case 'unused_index':
      return {
        title: `L’index « ${finding.index ?? ''} » de « ${finding.table} » n’a servi à aucune lecture${finding.detail === null ? '' : ` (${finding.detail})`}.`,
        help: 'Les compteurs du moteur repartent à zéro au redémarrage du serveur : un index utilisé seulement en fin de mois ou par un traitement de nuit peut paraître inutile. Observez une période représentative avant de décider. Sur MySQL et MariaDB, rendez-le d’abord invisible (ignoré) : le moteur ne s’en sert plus mais le garde, et le rétablir est immédiat ; il reste cependant mis à jour à chaque écriture, l’essai ne mesure donc pas le gain côté écritures. PostgreSQL n’a pas d’index invisible : la suppression se prépare avec prudence. Un index qui porte une contrainte n’est jamais proposé.',
      }
    case 'counter_near_limit':
      return {
        title: `Le compteur de « ${finding.table} » sur ${finding.columns.join(', ')} approche de la limite de son type${finding.detail === null ? '' : ` (${finding.detail})`}.`,
        help: 'Quand il atteint la limite, chaque insertion échoue d’un coup avec une erreur de doublon. Passer à un type plus large règle le problème, mais la modification réécrit toute la table (et les colonnes qui la référencent doivent suivre) : à planifier avant la saturation. La valeur lue est celle du moment, sans compter les lignes.',
      }
  }
}

function lines(count: number): string {
  return count === 1 ? 'ligne' : 'lignes'
}

const RULE_NAMES: Record<AuditCheck['rule'], string> = { unused_index: 'Index jamais lus' }

const REASONS: Record<string, string> = {
  performance_schema_off: 'performance_schema est désactivé sur ce serveur',
  instrument_off: 'l’instrument d’E/S des tables est désactivé sur ce serveur',
  permission_denied: 'le compte n’a pas le droit de lire les compteurs d’index',
}

function duration(seconds: number): string {
  const days = Math.floor(seconds / 86400)
  const hours = Math.floor((seconds % 86400) / 3600)
  const minutes = Math.floor((seconds % 3600) / 60)
  if (days > 0) {
    return `${days} j ${hours} h`
  }

  return hours > 0 ? `${hours} h ${minutes} min` : `${minutes} min`
}

/** Ce qu'une règle d'exploitation n'a pas pu vérifier, et pourquoi. */
export function describeCheck(check: AuditCheck): string {
  const name = RULE_NAMES[check.rule]
  if (check.state === 'too_short') {
    return `${name} : non vérifiable, la collecte des compteurs ne date que de ${duration(Number(check.detail ?? 0))} (il en faut 7 jours).`
  }
  const reason = check.reason === null ? undefined : REASONS[check.reason]

  return reason === undefined ? `${name} : non vérifiable, les compteurs n’ont pas pu être lus.` : `${name} : non vérifiable, ${reason}.`
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
  const table = `« ${finding.relation ?? 'la table'} »`

  switch (finding.rule) {
    case 'large_sort':
      return `Tri de ${table}${rows} sans LIMIT. Ce tri n’indique pas un accès disque, mais il lit et ordonne toutes les lignes : un index sur les colonnes triées, ou une limite, peut l’éviter. Estimation du moteur.`
    case 'leading_wildcard':
      return `Parcours complet de ${table}${rows}${condition}. Un LIKE dont le motif commence par un joker (%) ne peut pas s’appuyer sur un index ordinaire : chercher un début de texte, ou un index de recherche plein texte, selon le moteur.`
    default:
      return `Parcours complet de ${table}${rows}${condition}. Un index sur les colonnes filtrées peut l’éviter.`
  }
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

const LINT_RULE_LABELS: Record<string, string> = {
  function_on_column: 'Fonction sur une colonne',
  arithmetic_on_column: 'Calcul sur une colonne',
  leading_wildcard_like: 'LIKE avec joker en tête',
  select_star: 'SELECT *',
  large_offset: 'Grand OFFSET',
  not_in_subquery: 'NOT IN (sous-requête)',
  row_lock: 'Verrou de lignes',
  recursive_cte: 'Requête récursive',
  type_mismatch: 'Types comparés différents',
  or_on_columns: 'OR entre colonnes différentes',
  distinct_join: 'DISTINCT après jointure',
  count_star_large: 'COUNT(*) sur une grande table',
  random_order: 'Tri aléatoire de toute la table',
}

/** Les règles du linter de requête que le serveur envoie. */
export const LINT_RULES = Object.keys(LINT_RULE_LABELS)

/** Le nom français d'une règle du linter ; le code lui-même pour une règle que cette version ne connaît pas. */
export function lintRuleLabel(rule: string): string {
  return LINT_RULE_LABELS[rule] ?? rule
}
