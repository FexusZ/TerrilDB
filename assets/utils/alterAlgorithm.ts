import type { BigTableAssessment, Engine, StructureStatement } from '../api/types'
import { formatBytes } from './format'

/** Au-delà de ces seuils, une copie ou une réécriture de la table mérite un avertissement avant « Valider ». */
export const COPY_WARN_ROWS = 100_000
export const COPY_WARN_BYTES = 100 * 1024 * 1024

export interface AlgorithmPill {
  label: string
  tone: 'ok' | 'warn' | 'unknown'
}

/** Rien à dire quand le serveur n’a rien annoncé, ou quand ce n’est pas un ALTER ou que le moteur n’a pas de règles. */
function silent(statement: StructureStatement): boolean {
  return statement.algorithm === undefined || (statement.algorithm === 'unknown' && (statement.algorithmReason === 'unsupported_engine' || statement.algorithmReason === 'not_an_alter'))
}

export function algorithmPill(statement: StructureStatement): AlgorithmPill | null {
  if (silent(statement)) {
    return null
  }
  switch (statement.algorithm) {
    case 'instant':
      return { label: 'instantané', tone: 'ok' }
    case 'inplace':
      return { label: 'en place', tone: 'ok' }
    case 'copy':
      return { label: 'recopie', tone: 'warn' }
    case 'rewrite':
      return { label: 'réécriture', tone: 'warn' }
    default:
      return { label: 'algorithme inconnu', tone: 'unknown' }
  }
}

const REASONS: Record<string, string> = {
  unverified_version: 'Algorithme inconnu : cette version du serveur n’a pas été vérifiée, rien n’est affirmé.',
  not_innodb: 'Algorithme inconnu : la table n’est pas InnoDB, rien n’est affirmé.',
  depends_on_sql_mode: 'Algorithme inconnu : MySQL ne fait ce changement en place que sous un sql_mode strict, sinon il recopie la table.',
  unverified_change: 'Algorithme inconnu : ce cas n’est pas couvert par les règles vérifiées, rien n’est affirmé.',
}

/** Une phrase sur ce que le moteur fera probablement ; nulle quand il n’y a rien à dire. */
export function algorithmDetail(statement: StructureStatement): string | null {
  if (silent(statement)) {
    return null
  }
  const extra = statement.estimatedExtraBytes === null || statement.estimatedExtraBytes === undefined ? '' : ` Prévoir environ ${formatBytes(statement.estimatedExtraBytes)} de place disque en plus.`
  switch (statement.algorithm) {
    case 'instant':
      return 'Probablement instantané : seules les métadonnées changent, les données ne sont pas copiées.'
    case 'inplace':
      if (statement.blocksWrites === true) {
        return 'Probablement sans copie de la table, mais bloque les écritures pendant l’opération.'
      }

      return statement.blocksWrites === false ? 'Probablement en place, sans copie bloquante : les écritures restent possibles.' : 'Probablement en place, sans copie bloquante.'
    case 'copy':
      return `Probablement recopie toute la table${statement.blocksWrites === true ? ' et bloque les écritures pendant ce temps' : ' (le blocage des écritures dépend du serveur : non vérifié)'}.${extra}`
    case 'rewrite':
      return `Probablement réécrit toute la table${statement.blocksWrites === true ? ' en bloquant l’accès à la table pendant ce temps' : ''}.${extra}`
    default:
      return REASONS[statement.algorithmReason ?? ''] ?? REASONS.unverified_change ?? null
  }
}

const ONLINE_TOOLS: Partial<Record<Engine, string>> = {
  mysql: 'un outil externe de changement de schéma en ligne (gh-ost, pt-online-schema-change)',
  mariadb: 'un outil externe de changement de schéma en ligne (pt-online-schema-change)',
  postgresql: 'une migration en plusieurs étapes (nouvelle colonne, copie par lots, bascule)',
}

/**
 * L’avertissement avant « Valider » : une copie ou une réécriture d’une grosse table (au-delà de 100 000 lignes estimées ou de
 * 100 Mo, ou ce que la politique de l’installation juge volumineux). Nul sinon. TerrilDB ne lance jamais d’outil externe.
 */
export function copyWarning(statements: StructureStatement[], bigTable: BigTableAssessment | undefined, engine: Engine): string | null {
  const copying = statements.filter((statement) => statement.algorithm === 'copy' || statement.algorithm === 'rewrite')
  if (copying.length === 0) {
    return null
  }
  const rows = bigTable?.estimatedRows ?? null
  const bytes = Math.max(0, ...copying.map((statement) => statement.estimatedExtraBytes ?? 0))
  const rowsBig = rows !== null && rows >= COPY_WARN_ROWS
  if (!rowsBig && bytes < COPY_WARN_BYTES && (bigTable === undefined || bigTable.level === 'none')) {
    return null
  }
  const size = [rows === null ? null : `environ ${rows.toLocaleString('fr-FR')} lignes`, bytes > 0 ? formatBytes(bytes) : null].filter((part) => part !== null).join(', ')
  const tool = ONLINE_TOOLS[engine]

  return `Cette modification recopie toute la table (${size}) : elle peut être longue et demander autant de place disque que la table.${tool === undefined ? '' : ` Pour une très grosse table, envisagez ${tool}, lancé à part : TerrilDB n’en lance aucun.`}`
}
