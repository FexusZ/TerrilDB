import type { Cell, QueryResult, TableDetail } from '../api/types'

/** Une valeur saisie : du texte, ou NULL. */
export type Typed = string | null

/**
 * Les changements en attente sur les lignes lues : `edits` (numéro de ligne -> colonne -> nouvelle valeur), `deleted` (numéros de
 * ligne) et `inserts` (lignes à ajouter ; une cellule vide = valeur par défaut du moteur).
 */
export interface DataDraft {
  edits: Record<number, Record<string, Typed>>
  deleted: Set<number>
  inserts: Record<string, Typed>[]
}

export interface DataPayload {
  updates: { original: Record<string, Cell>; changes: Record<string, Typed> }[]
  inserts: { values: Record<string, Typed> }[]
  deletes: { original: Record<string, Cell> }[]
}

export const emptyDraft = (): DataDraft => ({ edits: {}, deleted: new Set(), inserts: [] })

/** Dit si le résultat affiché peut être édité : il faut retrouver chaque ligne de façon sûre. */
export function editability(table: TableDetail | null, result: QueryResult | null): { editable: boolean; reason: string | null } {
  if (table === null || result === null) {
    return { editable: false, reason: null }
  }
  if (table.kind !== 'table') {
    return { editable: false, reason: 'Une vue ne se modifie pas.' }
  }
  if (result.affectedRows !== null) {
    return { editable: false, reason: null }
  }
  if (result.truncated) {
    return { editable: false, reason: 'Résultat limité : ajoutez un LIMIT plus petit pour pouvoir le modifier.' }
  }
  const known = new Set(table.columns.map((column) => column.name))
  if (result.columns.some((column) => !known.has(column)) || new Set(result.columns).size !== result.columns.length) {
    return { editable: false, reason: 'Lecture seule : la requête ne renvoie pas les colonnes de la table telles quelles.' }
  }
  const primary = table.indexes.find((index) => index.primary)
  const needed = primary === undefined ? table.columns.map((column) => column.name) : primary.columns
  if (needed.some((column) => !result.columns.includes(column))) {
    return {
      editable: false,
      reason: primary === undefined ? 'Lecture seule : sans clé primaire, toutes les colonnes de la table doivent être sélectionnées.' : 'Lecture seule : sélectionnez la clé primaire pour pouvoir modifier les lignes.',
    }
  }

  return { editable: true, reason: null }
}

const same = (original: Cell | undefined, typed: Typed): boolean => (original === null || original === undefined ? typed === null : typed !== null && String(original) === typed)

/** Pose la valeur d'une cellule ; remise à sa valeur d'origine, elle n'est plus un changement. */
export function setCell(draft: DataDraft, read: Pick<QueryResult, 'columns' | 'rows'>, row: number, column: string, value: Typed): void {
  const original = read.rows[row]?.[read.columns.indexOf(column)]
  const edits = { ...(draft.edits[row] ?? {}) }
  if (same(original, value)) {
    delete edits[column]
  } else {
    edits[column] = value
  }
  if (Object.keys(edits).length === 0) {
    delete draft.edits[row]
  } else {
    draft.edits[row] = edits
  }
}

const blank = (values: Record<string, Typed>): boolean => Object.values(values).every((value) => value === '')

export function pendingCount(draft: DataDraft): number {
  return Object.keys(draft.edits).filter((index) => !draft.deleted.has(Number(index))).length + draft.deleted.size + draft.inserts.filter((row) => !blank(row)).length
}

export function buildPayload(read: Pick<QueryResult, 'columns' | 'rows'>, draft: DataDraft): DataPayload {
  const original = (index: number): Record<string, Cell> => Object.fromEntries(read.columns.map((name, position) => [name, read.rows[index]?.[position] ?? null]))
  const updates = Object.entries(draft.edits)
    .map(([index, changes]) => [Number(index), changes] as const)
    .filter(([index]) => !draft.deleted.has(index))
    .map(([index, changes]) => ({ original: original(index), changes }))
  const inserts = draft.inserts
    .filter((row) => !blank(row))
    .map((row) => ({ values: Object.fromEntries(Object.entries(row).filter(([, value]) => value !== '')) }))
  const deletes = [...draft.deleted].sort((a, b) => a - b).map((index) => ({ original: original(index) }))

  return { updates, inserts, deletes }
}
