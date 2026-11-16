import type { AuditCheck, DesignRule, OperationsRule, PlanFindingInfo, PlanKind, SchemaFinding } from '../api/types'
import { describeDesignFinding } from './designWording'
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
    case 'partition_range_exhausted':
      return {
        title: `La plage de partitions de « ${finding.table} » sur ${finding.columns.join(', ')} s’arrête au ${finding.detail ?? ''}, sans partition pour la suite.`,
        help: 'Le moteur refuse toute ligne dont la date dépasse la dernière borne tant qu’aucune partition fourre-tout (MAXVALUE ou DEFAULT) ou nouvelle partition ne la reçoit. Ajoutez-en une avant que des écritures n’échouent.',
      }
    case 'too_many_partitions':
      return {
        title: `La table « ${finding.table} » a ${finding.detail ?? ''} partitions.`,
        help: 'Au-delà d’environ 1000 partitions, le moteur met plus de temps à planifier les requêtes et à ouvrir la table. Regroupez les partitions (par exemple par mois plutôt que par jour) ou archivez les plus anciennes.',
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
    // Règles de conception (suite de #78) : leurs mots sont dans designWording.ts.
    default:
      return describeDesignFinding({ ...finding, rule: finding.rule })
  }
}

const OPERATIONS_RULES: Record<OperationsRule, string> = {
  stale_statistics: 'Statistiques périmées',
  dead_tuples: 'Tuples morts',
  reclaimable_space: 'Place libre',
  orphan_definer: 'Créateurs d’objets',
}

/** Ce qu'une règle d'exploitation n'a pas pu vérifier, et pourquoi. */
function describeOperationsCheck(check: AuditCheck & { rule: OperationsRule }): string {
  const reason = check.reason === 'permission_denied' ? 'le compte n’a pas le droit de lire ces informations' : 'ces informations n’ont pas pu être lues'

  return `${OPERATIONS_RULES[check.rule]} : non vérifiable, ${reason}.`
}

const RULE_NAMES: Record<'unused_index', string> = { unused_index: 'Index jamais lus' }

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
  if (check.rule !== 'unused_index') {
    return describeOperationsCheck({ ...check, rule: check.rule })
  }
  const name = RULE_NAMES[check.rule]
  if (check.state === 'too_short') {
    return `${name} : non vérifiable, la collecte des compteurs ne date que de ${duration(Number(check.detail ?? 0))} (il en faut 7 jours).`
  }
  const reason = check.reason === null ? undefined : REASONS[check.reason]

  return reason === undefined ? `${name} : non vérifiable, les compteurs n’ont pas pu être lus.` : `${name} : non vérifiable, ${reason}.`
}

export function describePlanFinding(finding: PlanFindingInfo): string {
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
