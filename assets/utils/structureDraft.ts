import type { ReferentialActionName, StructurePayload, TableDetail } from '../api/types'

/**
 * Le brouillon d'une table en cours de modification. Les colonnes ont un identifiant propre (`uid`) : les index, la clé primaire et
 * les clés étrangères s'y réfèrent par lui, si bien que renommer une colonne les suit sans rien casser. `originalName` dit de quelle
 * colonne, index ou clé de la table réelle vient une ligne ; nul, elle est nouvelle (c'est ce qui permet au serveur de reconnaître un
 * renommage). Une ligne existante qu'on retire reste là, barrée (`removed`) : on la voit et on peut la restaurer.
 */
export interface DraftColumn {
  uid: string
  originalName: string | null
  name: string
  type: string
  nullable: boolean
  default: string
  autoIncrement: boolean
  removed: boolean
  /** Colonne dont le moteur porte une particularité que le serveur ne sait pas redire (générée…) : montrée, jamais redéfinie. */
  lock?: string | null
}

export interface DraftIndex {
  uid: string
  originalName: string | null
  name: string
  /** Identifiants de colonnes ; pour un index `locked`, les noms bruts (la clé de tri de ClickHouse peut être une expression). */
  columns: string[]
  unique: boolean
  /** ClickHouse seulement : minmax, bloom_filter, set(n). */
  kind: string | null
  granularity: number | null
  removed: boolean
  /** Lecture seule (clé de tri de ClickHouse, ou index que le serveur ne sait pas recréer) : montré, jamais modifié. */
  locked: boolean
  /** Pourquoi, pour un index que le serveur ne sait pas recréer (invisible, FULLTEXT…). */
  lock?: string | null
}

export interface DraftForeignKey {
  uid: string
  originalName: string | null
  name: string
  columns: string[]
  referencedTable: string
  referencedColumns: string[]
  onUpdate: ReferentialActionName
  onDelete: ReferentialActionName
  removed: boolean
}

export interface Draft {
  name: string
  columns: DraftColumn[]
  indexes: DraftIndex[]
  foreignKeys: DraftForeignKey[]
  /** Identifiants des colonnes de la clé primaire, dans l'ordre de la clé. */
  primary: string[]
  /** Nom de la clé primaire réelle (PostgreSQL le réclame pour la retirer) ; nul si la table n'en a pas. */
  primaryOriginalName: string | null
  /** La clé primaire de la table réelle, pour pouvoir la restaurer après l'avoir retirée. */
  originalPrimary: string[]
}

export type MarkStatus = 'same' | 'new' | 'changed' | 'removed'
export interface Mark {
  status: MarkStatus
  fields: Set<string>
}

let sequence = 0
const nextUid = (): string => `u${String(++sequence)}`

const SORT_KEYS = ['ORDER BY', 'PRIMARY KEY']

/** Casse, espaces et « (10,2) » / « (10, 2) » ne font pas deux types (même règle que le serveur). */
export function normalizeType(type: string): string {
  return type.trim().replace(/\s+/g, ' ').toLowerCase().replace(/\(\s+/g, '(').replace(/\s+\)/g, ')').replace(/\s*,\s*/g, ',')
}

export function draftFromDetail(detail: TableDetail, clickhouse = false): Draft {
  const columns: DraftColumn[] = detail.columns.map((column) => ({
    uid: nextUid(),
    originalName: column.name,
    name: column.name,
    type: column.type,
    nullable: column.nullable,
    default: column.default ?? '',
    autoIncrement: column.autoIncrement,
    removed: false,
    lock: column.lock ?? null,
  }))
  const uidOf = (name: string): string => columns.find((column) => column.originalName === name)?.uid ?? name
  const primaryIndex = detail.indexes.find((index) => index.primary) ?? null

  return {
    name: detail.name,
    columns,
    indexes: detail.indexes
      .filter((index) => !index.primary)
      .map((index) => {
        const lock = index.lock ?? null
        const locked = (clickhouse && SORT_KEYS.includes(index.name)) || lock !== null

        return {
          uid: nextUid(),
          originalName: index.name,
          name: index.name,
          columns: locked ? [...index.columns] : index.columns.map(uidOf),
          unique: index.unique,
          kind: null,
          granularity: null,
          removed: false,
          locked,
          lock,
        }
      }),
    foreignKeys: detail.foreignKeys.map((key) => ({
      uid: nextUid(),
      originalName: key.name,
      name: key.name,
      columns: key.columns.map(uidOf),
      referencedTable: key.referencedTable,
      referencedColumns: [...key.referencedColumns],
      onUpdate: key.onUpdate as ReferentialActionName,
      onDelete: key.onDelete as ReferentialActionName,
      removed: false,
    })),
    primary: primaryIndex === null ? [] : primaryIndex.columns.map(uidOf),
    primaryOriginalName: primaryIndex?.name ?? null,
    originalPrimary: primaryIndex === null ? [] : primaryIndex.columns.map(uidOf),
  }
}

export function addColumn(draft: Draft, nullable = true): DraftColumn {
  const column: DraftColumn = { uid: nextUid(), originalName: null, name: '', type: '', nullable, default: '', autoIncrement: false, removed: false }
  draft.columns.push(column)

  return column
}

export function addIndex(draft: Draft): DraftIndex {
  const index: DraftIndex = { uid: nextUid(), originalName: null, name: '', columns: [], unique: false, kind: null, granularity: null, removed: false, locked: false }
  draft.indexes.push(index)

  return index
}

export function addForeignKey(draft: Draft): DraftForeignKey {
  const key: DraftForeignKey = { uid: nextUid(), originalName: null, name: '', columns: [], referencedTable: '', referencedColumns: [], onUpdate: 'no_action', onDelete: 'no_action', removed: false }
  draft.foreignKeys.push(key)

  return key
}

function removeFrom<T extends { uid: string; originalName: string | null; removed: boolean }>(list: T[], item: T): void {
  if (item.originalName === null) {
    list.splice(list.indexOf(item), 1)
  } else {
    item.removed = true
  }
}

export const removeColumn = (draft: Draft, column: DraftColumn): void => removeFrom(draft.columns, column)
export const removeIndex = (draft: Draft, index: DraftIndex): void => removeFrom(draft.indexes, index)
export const removeForeignKey = (draft: Draft, key: DraftForeignKey): void => removeFrom(draft.foreignKeys, key)

export function togglePrimary(draft: Draft, column: DraftColumn): void {
  draft.primary = draft.primary.includes(column.uid) ? draft.primary.filter((uid) => uid !== column.uid) : [...draft.primary, column.uid]
}

const sameList = (a: string[], b: string[]): boolean => a.length === b.length && a.every((value, position) => value === b[position])

/** Les noms que portait une liste d'identifiants de colonnes dans la table RÉELLE (un renommage ne compte pas). */
function originalNames(draft: Draft, uids: string[]): string[] {
  return uids.map((uid) => {
    const column = draft.columns.find((candidate) => candidate.uid === uid)

    return column === undefined ? uid : (column.originalName ?? column.name)
  })
}

export function columnState(draft: Draft, detail: TableDetail, column: DraftColumn): Mark {
  if (column.originalName === null) {
    return { status: 'new', fields: new Set() }
  }
  if (column.removed) {
    return { status: 'removed', fields: new Set() }
  }
  const original = detail.columns.find((candidate) => candidate.name === column.originalName)
  const fields = new Set<string>()
  if (original === undefined) {
    return { status: 'new', fields }
  }
  if (column.name !== original.name) {
    fields.add('name')
  }
  if (normalizeType(column.type) !== normalizeType(original.type)) {
    fields.add('type')
  }
  if (column.nullable !== original.nullable) {
    fields.add('nullable')
  }
  if (column.default.trim() !== (original.default ?? '')) {
    fields.add('default')
  }
  if (column.autoIncrement !== original.autoIncrement) {
    fields.add('autoIncrement')
  }
  const wasPrimary = detail.indexes.some((index) => index.primary && index.columns.includes(original.name))
  if (wasPrimary !== draft.primary.includes(column.uid)) {
    fields.add('primary')
  }

  return { status: fields.size === 0 ? 'same' : 'changed', fields }
}

export function indexState(draft: Draft, detail: TableDetail, index: DraftIndex): Mark {
  if (index.originalName === null) {
    return { status: 'new', fields: new Set() }
  }
  if (index.removed) {
    return { status: 'removed', fields: new Set() }
  }
  const original = detail.indexes.find((candidate) => !candidate.primary && candidate.name === index.originalName)
  const fields = new Set<string>()
  if (original === undefined) {
    return { status: 'new', fields }
  }
  const names = index.locked ? index.columns : originalNames(draft, index.columns.filter((uid) => draft.columns.find((c) => c.uid === uid)?.removed !== true))
  if (index.name !== original.name) {
    fields.add('name')
  }
  if (!sameList(names, original.columns)) {
    fields.add('columns')
  }
  if (index.unique !== original.unique) {
    fields.add('unique')
  }

  return { status: fields.size === 0 ? 'same' : 'changed', fields }
}

export function foreignKeyState(draft: Draft, detail: TableDetail, key: DraftForeignKey): Mark {
  if (key.originalName === null) {
    return { status: 'new', fields: new Set() }
  }
  if (key.removed) {
    return { status: 'removed', fields: new Set() }
  }
  const original = detail.foreignKeys.find((candidate) => candidate.name === key.originalName)
  const fields = new Set<string>()
  if (original === undefined) {
    return { status: 'new', fields }
  }
  if (key.name !== original.name) {
    fields.add('name')
  }
  if (!sameList(originalNames(draft, key.columns), original.columns)) {
    fields.add('columns')
  }
  if (key.referencedTable !== original.referencedTable || !sameList(key.referencedColumns, original.referencedColumns)) {
    fields.add('reference')
  }
  if (key.onUpdate !== original.onUpdate) {
    fields.add('onUpdate')
  }
  if (key.onDelete !== original.onDelete) {
    fields.add('onDelete')
  }

  return { status: fields.size === 0 ? 'same' : 'changed', fields }
}

/** La clé primaire, vue comme un index parmi les autres : nouvelle, modifiée, retirée ou inchangée. */
export function primaryState(draft: Draft, detail: TableDetail): Mark {
  const original = detail.indexes.find((index) => index.primary)
  const live = draft.primary.filter((uid) => draft.columns.some((column) => column.uid === uid && !column.removed))
  if (original === undefined) {
    return { status: live.length > 0 ? 'new' : 'same', fields: new Set() }
  }
  if (live.length === 0) {
    return { status: 'removed', fields: new Set() }
  }

  return sameList(originalNames(draft, live), original.columns) ? { status: 'same', fields: new Set() } : { status: 'changed', fields: new Set(['columns']) }
}

export function removePrimary(draft: Draft): void {
  draft.primary = []
}

export function restorePrimary(draft: Draft): void {
  draft.primary = [...draft.originalPrimary]
}

/** Décale un élément d'une place (l'ordre des colonnes d'un index compte) ; en bout de liste, rien ne bouge. */
export function moveItem(list: string[], index: number, delta: -1 | 1): string[] {
  const target = index + delta
  if (index < 0 || index >= list.length || target < 0 || target >= list.length) {
    return [...list]
  }
  const next = [...list]
  ;[next[index], next[target]] = [next[target] as string, next[index] as string]

  return next
}

export function isDirty(draft: Draft, detail: TableDetail): boolean {
  if (draft.name !== detail.name) {
    return true
  }
  const marks = [
    ...draft.columns.map((column) => columnState(draft, detail, column)),
    ...draft.indexes.map((index) => indexState(draft, detail, index)),
    ...draft.foreignKeys.map((key) => foreignKeyState(draft, detail, key)),
  ]
  if (marks.some((mark) => mark.status !== 'same')) {
    return true
  }
  const originalPrimary = detail.indexes.find((index) => index.primary)?.columns ?? []

  return !sameList(originalNames(draft, draft.primary), originalPrimary)
}

/** Les noms (actuels) des colonnes encore présentes parmi ces identifiants ; une colonne retirée disparaît de ce qui la portait. */
function liveNames(draft: Draft, uids: string[]): string[] {
  const names: string[] = []
  for (const uid of uids) {
    const column = draft.columns.find((candidate) => candidate.uid === uid)
    if (column !== undefined && !column.removed) {
      names.push(column.name)
    }
  }

  return names
}

export function toPayload(draft: Draft): StructurePayload {
  const indexes: StructurePayload['indexes'] = []
  for (const index of draft.indexes) {
    if (index.removed) {
      continue
    }
    const columns = index.locked ? index.columns : liveNames(draft, index.columns)
    if (columns.length > 0) {
      indexes.push({ originalName: index.originalName, name: index.name, columns, unique: index.unique, primary: false, kind: index.kind, granularity: index.granularity })
    }
  }
  // Colonnes de la clé primaire : celles qui y étaient gardent leur place, les nouvelles suivent dans l'ordre de la table.
  const primaryColumns = liveNames(draft, draft.primary)
  if (primaryColumns.length > 0) {
    indexes.push({ originalName: draft.primaryOriginalName, name: draft.primaryOriginalName ?? 'PRIMARY', columns: primaryColumns, unique: true, primary: true, kind: null, granularity: null })
  }

  return {
    name: draft.name,
    columns: draft.columns
      .filter((column) => !column.removed)
      .map((column) => ({
        originalName: column.originalName,
        name: column.name,
        type: column.type,
        nullable: column.nullable,
        default: column.default.trim() === '' ? null : column.default.trim(),
        autoIncrement: column.autoIncrement,
      })),
    indexes,
    foreignKeys: draft.foreignKeys
      .filter((key) => !key.removed)
      .flatMap((key) => {
        const columns = liveNames(draft, key.columns)

        return columns.length === 0 ? [] : [{ originalName: key.originalName, name: key.name, columns, referencedTable: key.referencedTable, referencedColumns: key.referencedColumns, onUpdate: key.onUpdate, onDelete: key.onDelete }]
      }),
  }
}

const OPERATIONS: Record<string, string> = {
  add_column: 'Ajouter la colonne',
  drop_column: 'Retirer la colonne',
  rename_column: 'Renommer la colonne',
  modify_column: 'Modifier la colonne',
  add_index: 'Ajouter l’index',
  drop_index: 'Retirer l’index',
  rename_index: 'Renommer l’index',
  add_primary_key: 'Définir la clé primaire',
  drop_primary_key: 'Retirer la clé primaire',
  add_foreign_key: 'Ajouter la clé étrangère',
  drop_foreign_key: 'Retirer la clé étrangère',
  rename_table: 'Renommer la table',
  drop_table: 'Supprimer la table',
}

/** Ce que fait une instruction, en français, pour la liste de confirmation et le journal. */
export function describeStatement(operation: string, subject: string): string {
  if (operation === 'materialize_index') {
    return `Matérialiser l’index « ${subject} » sur les données existantes`
  }

  return `${OPERATIONS[operation] ?? operation} « ${subject} »`
}
