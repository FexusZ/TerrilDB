<script setup lang="ts">
import { computed, onBeforeUnmount, ref, useId, watch } from 'vue'
import { ApiError } from '../api/client'
import { messageFor } from '../api/errors'
import { describeHolder, describeStatus } from '../utils/diagnostics'
import { formatBytes } from '../utils/format'
import { applyStructure, previewStructure, type StructureScope } from '../api/structure'
import { COLUMN_TYPES, ENGINE_STRUCTURE, type Engine, type ReferentialActionName, type StructureLockCheck, type StructurePreview, type TableDetail, type TableHolder } from '../api/types'
import { useWorkspaceStore } from '../stores/workspace'
import {
  addColumn,
  addForeignKey,
  addIndex,
  columnState,
  describeStatement,
  draftFromDetail,
  foreignKeyState,
  indexState,
  isDirty,
  moveItem,
  primaryState,
  removeColumn,
  removeForeignKey,
  removeIndex,
  removePrimary,
  restorePrimary,
  togglePrimary,
  toPayload,
  type Draft,
  type DraftColumn,
  type DraftForeignKey,
  type DraftIndex,
  type Mark,
} from '../utils/structureDraft'
import AppButton from './AppButton.vue'
import ConfirmDialog from './ConfirmDialog.vue'

const props = defineProps<{ connectionId: string; engine: Engine; table: TableDetail; scope: StructureScope }>()
const emit = defineEmits<{ applied: [table: TableDetail | null, previousName: string]; reload: [] }>()

const workspace = useWorkspaceStore()
const features = computed(() => ENGINE_STRUCTURE[props.engine])
const uid = useId()
const typeListId = `${uid}-types`
const tableListId = `${uid}-tables`

const draft = ref<Draft>(draftFromDetail(props.table, features.value.skipIndex))
const dirty = computed(() => isDirty(draft.value, props.table))
const payload = computed(() => toPayload(draft.value))

const ACTIONS: { value: ReferentialActionName; label: string }[] = [
  { value: 'no_action', label: 'aucune action' },
  { value: 'restrict', label: 'blocage' },
  { value: 'cascade', label: 'en cascade' },
  { value: 'set_null', label: 'mise à NULL' },
  { value: 'set_default', label: 'valeur par défaut' },
]
const SKIP_KINDS = ['minmax', 'bloom_filter', 'set(100)']

const markOf = (column: DraftColumn): Mark => columnState(draft.value, props.table, column)
const markOfIndex = (index: DraftIndex): Mark => indexState(draft.value, props.table, index)
const primaryMark = computed<Mark>(() => primaryState(draft.value, props.table))

/** Une ligne du tableau des index : la clé primaire (comptée comme un index), un index, ou la clé de tri de ClickHouse (lecture seule). */
interface IndexRow {
  id: string
  kind: 'primary' | 'index' | 'locked'
  item: DraftIndex | null
  mark: Mark
  name: string
  unique: boolean
  /** Identifiants des colonnes encore présentes dans la table, dans l'ordre de l'index. */
  visible: string[]
  /** Noms bruts (index verrouillé). */
  locked: string[]
}

const showSize = computed(() => props.table.indexes.some((index) => (index.sizeBytes ?? null) !== null))

/** La taille connue d'un index déjà en base (un index nouveau n'en a pas encore). */
function sizeOf(row: IndexRow): string {
  const original = row.kind === 'primary' ? draft.value.primaryOriginalName : (row.item?.originalName ?? null)
  const known = original === null ? undefined : props.table.indexes.find((index) => index.name === original)

  return formatBytes(known?.sizeBytes ?? null)
}

const isLive = (uid: string): boolean => draft.value.columns.some((column) => column.uid === uid && !column.removed)
const columnName = (uid: string): string => draft.value.columns.find((column) => column.uid === uid)?.name ?? uid

const indexRows = computed<IndexRow[]>(() => {
  const rows: IndexRow[] = []
  if (features.value.primaryKey) {
    rows.push({ id: 'primary', kind: 'primary', item: null, mark: primaryMark.value, name: 'clé primaire', unique: true, visible: draft.value.primary.filter(isLive), locked: [] })
  }
  for (const item of draft.value.indexes) {
    rows.push({
      id: item.uid,
      kind: item.locked ? 'locked' : 'index',
      item,
      mark: markOfIndex(item),
      name: item.name,
      unique: item.unique,
      visible: item.locked ? [] : item.columns.filter(isLive),
      locked: item.locked ? item.columns : [],
    })
  }

  return rows
})

/** La liste d'identifiants que la ligne modifie : la clé primaire a la sienne, un index porte ses colonnes. */
const listOf = (row: IndexRow): string[] => (row.kind === 'primary' ? draft.value.primary : (row.item?.columns ?? []))

function writeList(row: IndexRow, visible: string[]): void {
  // Les colonnes retirées de la table restent en fin de liste, pour qu'une restauration les retrouve.
  const hidden = listOf(row).filter((uid) => !isLive(uid))
  if (row.kind === 'primary') {
    draft.value.primary = [...visible, ...hidden]
  } else if (row.item !== null) {
    row.item.columns = [...visible, ...hidden]
  }
}

/** Les colonnes qu'on peut mettre à cette place : celles de la table qui ne sont pas déjà dans l'index (plus celle d'origine). */
const choices = (row: IndexRow, keep: string | null): DraftColumn[] => liveColumns.value.filter((column) => column.uid === keep || !row.visible.includes(column.uid))

function replaceColumn(row: IndexRow, from: string, to: string): void {
  writeList(row, row.visible.map((uid) => (uid === from ? to : uid)))
}

function moveColumn(row: IndexRow, position: number, delta: -1 | 1): void {
  writeList(row, moveItem(row.visible, position, delta))
}

function dropColumn(row: IndexRow, uid: string): void {
  writeList(row, row.visible.filter((candidate) => candidate !== uid))
}

function addColumnTo(row: IndexRow, event: Event): void {
  const select = event.target as HTMLSelectElement
  if (select.value !== '') {
    writeList(row, [...row.visible, select.value])
  }
  select.value = ''
}

function restoreRow(row: IndexRow): void {
  if (row.kind === 'primary') {
    restorePrimary(draft.value)
  } else if (row.item !== null) {
    restore(row.item)
  }
}
const markOfKey = (key: DraftForeignKey): Mark => foreignKeyState(draft.value, props.table, key)
const edited = (mark: Mark, field: string): boolean => mark.status === 'changed' && mark.fields.has(field)

/** Les colonnes qu'on peut encore choisir pour un index ou une clé étrangère (pas celles qu'on retire). */
const liveColumns = computed(() => draft.value.columns.filter((column) => !column.removed))
const isPrimary = (column: DraftColumn): boolean => draft.value.primary.includes(column.uid)
const tableNames = computed(() => workspace.tables.map((table) => table.name))

/** Une clé étrangère associe ses colonnes à celles de la table référencée, position par position : une ligne par paire. */
const pairsOf = (key: DraftForeignKey): number[] => key.columns.flatMap((uid, at) => (isLive(uid) ? [at] : []))
const keyChoices = (key: DraftForeignKey, keep: string | null): DraftColumn[] => liveColumns.value.filter((column) => column.uid === keep || !key.columns.includes(column.uid))

function setPairedReference(key: DraftForeignKey, at: number, text: string): void {
  const next = key.columns.map((_, index) => key.referencedColumns[index] ?? '')
  next[at] = text.trim()
  key.referencedColumns = next
}

function movePair(key: DraftForeignKey, at: number, delta: -1 | 1): void {
  const live = pairsOf(key)
  const target = live[live.indexOf(at) + delta]
  if (target === undefined) {
    return
  }
  const columns = [...key.columns]
  const references = key.columns.map((_, index) => key.referencedColumns[index] ?? '')
  ;[columns[at], columns[target]] = [columns[target] as string, columns[at] as string]
  ;[references[at], references[target]] = [references[target] as string, references[at] as string]
  key.columns = columns
  key.referencedColumns = references
}

function dropPair(key: DraftForeignKey, at: number): void {
  key.referencedColumns = key.columns.map((_, index) => key.referencedColumns[index] ?? '').filter((_, index) => index !== at)
  key.columns = key.columns.filter((_, index) => index !== at)
}

function addPair(key: DraftForeignKey, event: Event): void {
  const select = event.target as HTMLSelectElement
  if (select.value !== '') {
    key.referencedColumns = [...key.columns.map((_, index) => key.referencedColumns[index] ?? ''), '']
    key.columns = [...key.columns, select.value]
  }
  select.value = ''
}

function restore(item: { removed: boolean }): void {
  item.removed = false
}

// Un brouillon incomplet (ligne nouvelle sans nom ou sans type…) n'est pas envoyé : on attend qu'il soit complet.
const incomplete = computed(() => {
  const d = draft.value
  const nameless = (value: string): boolean => value.trim() === ''

  return (
    nameless(d.name) ||
    d.columns.some((column) => !column.removed && (nameless(column.name) || nameless(column.type))) ||
    d.indexes.some((index) => !index.removed && !index.locked && (nameless(index.name) || index.columns.length === 0)) ||
    d.foreignKeys.some((key) => !key.removed && (nameless(key.name) || key.columns.length === 0 || nameless(key.referencedTable) || key.referencedColumns.length !== key.columns.length || key.referencedColumns.some(nameless)))
  )
})

const preview = ref<StructurePreview | null>(null)
const previewing = ref(false)
const problem = ref<string | null>(null)
const failure = ref<{ message: string; applied: string[]; holders: TableHolder[] | null } | null>(null)
const applying = ref(false)
const confirming = ref(false)
const dropping = ref<StructurePreview | null>(null)
const stale = ref(false)
let ticket = 0
let timer: ReturnType<typeof setTimeout> | undefined

function detailOf(error: unknown): string {
  const base = messageFor(error)

  return error instanceof ApiError && error.code === 'invalid_schema_change' && typeof error.details.detail === 'string' ? `${base} (${error.details.detail})` : base
}

async function refreshPreview(): Promise<void> {
  const mine = ++ticket
  previewing.value = true
  try {
    const result = await previewStructure(props.connectionId, props.table.name, { draft: payload.value, fingerprint: props.table.fingerprint }, props.scope)
    if (mine === ticket) {
      preview.value = result
      stale.value = result.stale
    }
  } catch (error) {
    if (mine === ticket) {
      problem.value = detailOf(error)
    }
  } finally {
    if (mine === ticket) {
      previewing.value = false
    }
  }
}

watch([payload, dirty, incomplete], () => {
  clearTimeout(timer)
  ticket++
  preview.value = null
  problem.value = null
  failure.value = null
  previewing.value = false
  if (dirty.value && !incomplete.value) {
    timer = setTimeout(() => void refreshPreview(), 300)
  }
})
onBeforeUnmount(() => clearTimeout(timer))

// La table change (après une application, ou un rechargement) : on repart de la table réelle.
watch(
  () => props.table,
  (table) => {
    draft.value = draftFromDetail(table, features.value.skipIndex)
    stale.value = false
  },
)

function cancel(): void {
  draft.value = draftFromDetail(props.table, features.value.skipIndex)
  confirming.value = false
}

async function validate(): Promise<void> {
  if (preview.value === null || preview.value.stale || applying.value) {
    return
  }
  if (preview.value.destructive) {
    confirming.value = true

    return
  }
  await apply(false)
}

async function apply(confirmed: boolean): Promise<void> {
  applying.value = true
  failure.value = null
  try {
    const result = await applyStructure(props.connectionId, props.table.name, { draft: payload.value, fingerprint: props.table.fingerprint, confirmed }, props.scope)
    confirming.value = false
    emit('applied', result.table, props.table.name)
  } catch (error) {
    confirming.value = false
    handleFailure(error)
  } finally {
    applying.value = false
  }
}

function handleFailure(error: unknown): void {
  if (error instanceof ApiError && error.code === 'schema_changed') {
    stale.value = true
    problem.value = messageFor(error)

    return
  }
  if (error instanceof ApiError && ['schema_change_failed', 'lock_timeout', 'deadlock', 'serialization_failure'].includes(error.code)) {
    const applied = Array.isArray(error.details.applied) ? (error.details.applied as { operation: string; subject: string }[]).map((s) => describeStatement(s.operation, s.subject)) : []
    const check = error.details.lockCheck as StructureLockCheck | null | undefined
    failure.value = {
      message: error.details.lockTimeout === true ? 'Une transaction ouverte retient cette table : la modification a attendu son verrou jusqu’au délai maximum.' : messageFor(error),
      applied,
      holders: error.details.lockTimeout === true && check?.status.availability === 'available' ? check.holders : null,
    }
    if (applied.length > 0) {
      // Une partie est déjà en place (MySQL, MariaDB, ClickHouse) : la table affichée n'est plus la vraie.
      stale.value = true
    }

    return
  }
  problem.value = detailOf(error)
}

async function askDrop(): Promise<void> {
  problem.value = null
  try {
    dropping.value = await previewStructure(props.connectionId, props.table.name, { drop: true, fingerprint: props.table.fingerprint }, props.scope)
  } catch (error) {
    problem.value = detailOf(error)
  }
}

async function confirmDrop(): Promise<void> {
  applying.value = true
  try {
    await applyStructure(props.connectionId, props.table.name, { drop: true, fingerprint: props.table.fingerprint, confirmed: true }, props.scope)
    dropping.value = null
    emit('applied', null, props.table.name)
  } catch (error) {
    dropping.value = null
    handleFailure(error)
  } finally {
    applying.value = false
  }
}

const lockUnreadable = computed(() => (preview.value?.lockCheck ? describeStatus(preview.value.lockCheck.status, 'les verrous') : null))
const destructive = computed(() => preview.value?.statements.filter((statement) => statement.destructive) ?? [])
</script>

<template>
  <div class="editor">
    <aside v-if="dirty" class="changes" role="region" aria-label="Modifications en attente">
      <div class="top">
        <strong>Modifications en attente</strong>
        <span v-if="preview" class="mode">{{ preview.transactional ? 'Appliquées en une seule transaction : tout ou rien.' : 'Appliquées instruction par instruction : en cas d’erreur, les précédentes restent en place.' }}</span>
      </div>
      <p v-if="incomplete" class="hint" role="status">Complétez les lignes nouvelles (nom et type) pour voir le SQL.</p>
      <p v-else-if="previewing" class="hint" role="status">Calcul du SQL…</p>
      <ul v-if="preview && preview.statements.length > 0" class="sql" aria-label="SQL qui sera exécuté">
        <li v-for="(statement, position) in preview.statements" :key="position" :class="{ destructive: statement.destructive }">
          <code>{{ statement.sql }};</code>
          <span v-if="statement.destructive" class="tag danger">détruit</span>
        </li>
      </ul>
      <p v-else-if="preview" class="hint" role="status">Ces modifications ne changent rien dans la base.</p>
      <div v-if="preview?.lockCheck" class="lock-check" role="status" data-test="lock-check" :class="{ held: preview.lockCheck.holders.length > 0 }">
        <template v-if="lockUnreadable">
          <p>Verrous : non vérifiable. {{ lockUnreadable }}</p>
        </template>
        <template v-else-if="preview.lockCheck.holders.length > 0">
          <p>{{ preview.lockCheck.holders.length }} session{{ preview.lockCheck.holders.length > 1 ? 's' : '' }} tien{{ preview.lockCheck.holders.length > 1 ? 'nent' : 't' }} cette table dans une transaction ouverte : l’application attendrait leur fin (au plus 10 s) et ferait attendre derrière elle les requêtes suivantes sur la table. Rien ne vous empêche de continuer.</p>
          <ul><li v-for="holder in preview.lockCheck.holders" :key="holder.session">{{ describeHolder(holder) }}</li></ul>
        </template>
        <p v-else>Aucune transaction ouverte ne tient cette table.</p>
      </div>
      <p v-if="stale && !failure" class="alert" role="alert">La table a changé depuis son chargement. <button type="button" class="link" @click="emit('reload')">Recharger la table</button></p>
      <p v-if="problem && !(stale && !failure)" class="alert" role="alert">{{ problem }}</p>
      <div v-if="failure" class="alert" role="alert" :data-test="failure.holders === null ? undefined : 'lock-timeout'">
        <p>{{ failure.message }}</p>
        <ul v-if="failure.holders !== null && failure.holders.length > 0"><li v-for="holder in failure.holders" :key="holder.session">{{ describeHolder(holder) }}</li></ul>
        <template v-if="failure.applied.length > 0">
          <p>Déjà appliqué avant l’erreur :</p>
          <ul><li v-for="line in failure.applied" :key="line">{{ line }}</li></ul>
          <button type="button" class="link" @click="emit('reload')">Recharger la table</button>
        </template>
        <p v-else>Rien n’a été modifié.</p>
      </div>
      <div class="buttons">
        <AppButton variant="ghost" small :disabled="applying" @click="cancel">Annuler</AppButton>
        <AppButton variant="primary" small :busy="applying" :disabled="preview === null || preview.statements.length === 0 || stale" @click="validate">Valider les changements</AppButton>
      </div>
    </aside>
    <p v-else-if="problem" class="alert" role="alert">{{ problem }}</p>

    <section class="name">
      <label :for="`${uid}-table-name`">Nom de la table</label>
      <input :id="`${uid}-table-name`" v-model="draft.name" class="field mono" :class="{ edited: draft.name !== table.name }" spellcheck="false" autocomplete="off">
    </section>

    <section class="columns">
      <h2>Colonnes</h2>
      <div class="box">
        <table>
          <thead>
            <tr>
              <th>Nom</th><th>Type</th><th>Null</th><th>Défaut</th><th v-if="features.autoIncrement">Auto</th><th v-if="features.primaryKey">Clé primaire</th><th><span class="sr-only">Actions</span></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="column in draft.columns" :key="column.uid" :data-status="markOf(column).status">
              <td><input v-model="column.name" class="field mono" :class="{ edited: edited(markOf(column), 'name') }" :disabled="column.removed" aria-label="Nom de la colonne" spellcheck="false" autocomplete="off"></td>
              <td><input v-model="column.type" class="field mono" :class="{ edited: edited(markOf(column), 'type') }" :list="typeListId" :disabled="column.removed" aria-label="Type de la colonne" spellcheck="false" autocomplete="off"></td>
              <td><input v-model="column.nullable" type="checkbox" :class="{ edited: edited(markOf(column), 'nullable') }" :disabled="column.removed" aria-label="Accepte NULL"></td>
              <td><input v-model="column.default" class="field mono" :class="{ edited: edited(markOf(column), 'default') }" :disabled="column.removed || column.autoIncrement" placeholder="aucun" aria-label="Valeur par défaut" spellcheck="false" autocomplete="off"></td>
              <td v-if="features.autoIncrement"><input v-model="column.autoIncrement" type="checkbox" :class="{ edited: edited(markOf(column), 'autoIncrement') }" :disabled="column.removed" aria-label="Auto-incrément"></td>
              <td v-if="features.primaryKey"><input type="checkbox" :checked="isPrimary(column)" :class="{ edited: edited(markOf(column), 'primary') }" :disabled="column.removed" aria-label="Dans la clé primaire" @change="togglePrimary(draft, column)"></td>
              <td class="act">
                <AppButton v-if="column.removed" variant="ghost" small @click="restore(column)">Restaurer</AppButton>
                <button v-else type="button" class="x" :aria-label="`Retirer la colonne ${column.name}`" @click="removeColumn(draft, column)">✕</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <div><AppButton variant="ghost" small @click="addColumn(draft, !features.skipIndex)">+ Ajouter une colonne</AppButton></div>
      <datalist :id="typeListId"><option v-for="type in COLUMN_TYPES[engine]" :key="type" :value="type" /></datalist>
    </section>

    <section class="indexes">
      <h2>Index</h2>
      <p v-if="indexRows.length === 0" class="none">Aucun index.</p>
      <div v-else class="box">
        <table class="rows-table index-table">
          <thead>
            <tr><th>Nom</th><th>Type</th><th v-if="features.uniqueIndex">Unique</th><th v-if="showSize">Taille</th><th class="rank-h">#</th><th>Colonne</th><th>Ordre</th><th><span class="sr-only">Actions</span></th></tr>
          </thead>
          <tbody v-for="row in indexRows" :key="row.id" :data-kind="row.kind" :data-status="row.mark.status">
            <template v-if="row.kind === 'locked'">
              <tr class="col-row">
                <td class="mono">{{ row.name }}</td>
                <td class="type"><span class="tag">clé de tri : lecture seule</span></td>
                <td v-if="features.uniqueIndex" />
                <td v-if="showSize" class="size-cell">{{ sizeOf(row) }}</td>
                <td colspan="3" class="mono dim">{{ row.locked.join(', ') }}</td>
                <td />
              </tr>
            </template>
            <template v-else>
              <tr v-for="(colUid, position) in row.visible" :key="colUid" class="col-row">
                <template v-if="position === 0">
                  <td class="name-cell" :rowspan="row.visible.length + 1">
                    <span v-if="row.kind === 'primary'" class="mono">{{ draft.primaryOriginalName ?? 'PRIMARY' }}</span>
                    <input v-else v-model="row.item!.name" class="field mono" :class="{ edited: edited(row.mark, 'name') }" :disabled="row.mark.status === 'removed' || (!features.renameIndex && row.item!.originalName !== null)" aria-label="Nom de l’index" spellcheck="false" autocomplete="off">
                  </td>
                  <td class="type" :rowspan="row.visible.length + 1">
                    <span class="tag" :class="{ pk: row.kind === 'primary' }">{{ row.kind === 'primary' ? 'clé primaire' : row.unique ? 'unique' : 'index' }}</span>
                    <template v-if="features.skipIndex && row.item !== null && row.item.originalName === null">
                      <select v-model="row.item.kind" class="field" aria-label="Type d’index de saut"><option :value="null">minmax</option><option v-for="kind in SKIP_KINDS.slice(1)" :key="kind" :value="kind">{{ kind }}</option></select>
                      <input v-model.number="row.item.granularity" class="field narrow" type="number" min="1" placeholder="1" aria-label="Granularité">
                    </template>
                  </td>
                  <td v-if="features.uniqueIndex" class="unique-cell" :rowspan="row.visible.length + 1">
                    <input v-if="row.kind === 'primary'" type="checkbox" checked disabled aria-label="Unique">
                    <input v-else v-model="row.item!.unique" type="checkbox" :class="{ edited: edited(row.mark, 'unique') }" :disabled="row.mark.status === 'removed'" aria-label="Unique">
                  </td>
                  <td v-if="showSize" class="size-cell" :rowspan="row.visible.length + 1">{{ sizeOf(row) }}</td>
                </template>
                <td class="rank-cell" :class="{ edited: edited(row.mark, 'columns') || (row.kind === 'primary' && row.mark.status === 'changed') }"><span class="rank">{{ position + 1 }}</span></td>
                <td class="cols" :class="{ edited: edited(row.mark, 'columns') || (row.kind === 'primary' && row.mark.status === 'changed') }">
                  <select class="field" :disabled="row.mark.status === 'removed'" :aria-label="`Colonne ${String(position + 1)} de l’index ${row.name}`" @change="replaceColumn(row, colUid, ($event.target as HTMLSelectElement).value)">
                    <option v-for="column in choices(row, colUid)" :key="column.uid" :value="column.uid" :selected="column.uid === colUid">{{ column.name }}</option>
                  </select>
                </td>
                <td class="moves">
                  <button type="button" class="step" :disabled="position === 0 || row.mark.status === 'removed'" :aria-label="`Monter ${columnName(colUid)} dans l’index ${row.name}`" @click="moveColumn(row, position, -1)">↑</button>
                  <button type="button" class="step" :disabled="position === row.visible.length - 1 || row.mark.status === 'removed'" :aria-label="`Descendre ${columnName(colUid)} dans l’index ${row.name}`" @click="moveColumn(row, position, 1)">↓</button>
                  <button type="button" class="x" :disabled="row.mark.status === 'removed'" :aria-label="`Ôter ${columnName(colUid)} de l’index ${row.name}`" @click="dropColumn(row, colUid)">✕</button>
                </td>
                <td v-if="position === 0" class="act" :rowspan="row.visible.length + 1">
                  <AppButton v-if="row.mark.status === 'removed'" variant="ghost" small @click="restoreRow(row)">Restaurer</AppButton>
                  <button v-else-if="row.kind === 'index'" type="button" class="x" :aria-label="`Retirer l’index ${row.name}`" @click="removeIndex(draft, row.item!)">✕</button>
                  <button v-else-if="row.visible.length > 0" type="button" class="x" aria-label="Retirer la clé primaire" @click="removePrimary(draft)">✕</button>
                </td>
              </tr>
              <tr class="add-row">
                <td v-if="row.visible.length === 0" class="name-cell">
                  <span v-if="row.kind === 'primary'" class="mono">{{ draft.primaryOriginalName ?? 'PRIMARY' }}</span>
                  <input v-else v-model="row.item!.name" class="field mono" aria-label="Nom de l’index" spellcheck="false" autocomplete="off">
                </td>
                <td v-if="row.visible.length === 0" class="type"><span class="tag" :class="{ pk: row.kind === 'primary' }">{{ row.kind === 'primary' ? 'clé primaire' : 'index' }}</span></td>
                <td v-if="row.visible.length === 0 && features.uniqueIndex" class="unique-cell">
                  <input v-if="row.kind === 'primary'" type="checkbox" checked disabled aria-label="Unique">
                  <input v-else v-model="row.item!.unique" type="checkbox" aria-label="Unique">
                </td>
                <td v-if="row.visible.length === 0 && showSize" class="size-cell">{{ sizeOf(row) }}</td>
                <td class="rank-cell" />
                <td class="cols">
                  <span v-if="row.kind === 'primary' && row.visible.length === 0" class="dim">Aucune clé primaire.&nbsp;</span>
                  <select class="field" :disabled="row.mark.status === 'removed' || choices(row, null).length === 0" :aria-label="`Ajouter une colonne à l’index ${row.name}`" @change="addColumnTo(row, $event)">
                    <option value="">+ Ajouter une colonne…</option>
                    <option v-for="column in choices(row, null)" :key="column.uid" :value="column.uid">{{ column.name }}</option>
                  </select>
                </td>
                <td class="moves" />
                <td v-if="row.visible.length === 0" class="act">
                  <AppButton v-if="row.mark.status === 'removed'" variant="ghost" small @click="restoreRow(row)">Restaurer</AppButton>
                  <button v-else-if="row.kind === 'index'" type="button" class="x" :aria-label="`Retirer l’index ${row.name}`" @click="removeIndex(draft, row.item!)">✕</button>
                </td>
              </tr>
            </template>
          </tbody>
        </table>
      </div>
      <div><AppButton variant="ghost" small @click="addIndex(draft)">+ Ajouter un index</AppButton></div>
    </section>

    <section v-if="features.foreignKeys" class="foreign-keys">
      <h2>Clés étrangères</h2>
      <p v-if="draft.foreignKeys.length === 0" class="none">Aucune clé étrangère.</p>
      <div v-else class="box">
        <table class="rows-table fk-table">
          <thead>
            <tr><th>Nom</th><th>#</th><th>Colonne</th><th>Colonne référencée</th><th>Ordre</th><th>Table référencée</th><th>À la suppression</th><th>À la mise à jour</th><th><span class="sr-only">Actions</span></th></tr>
          </thead>
          <tbody v-for="key in draft.foreignKeys" :key="key.uid" :data-status="markOfKey(key).status">
            <tr v-for="(at, position) in pairsOf(key)" :key="key.columns[at]" class="col-row">
              <template v-if="position === 0">
                <td class="name-cell" :rowspan="pairsOf(key).length + 1"><input v-model="key.name" class="field mono" :class="{ edited: edited(markOfKey(key), 'name') }" :disabled="key.removed" aria-label="Nom de la clé étrangère" spellcheck="false" autocomplete="off"></td>
              </template>
              <td class="rank-cell" :class="{ edited: edited(markOfKey(key), 'columns') }"><span class="rank">{{ position + 1 }}</span></td>
              <td class="cols" :class="{ edited: edited(markOfKey(key), 'columns') }">
                <select class="field" :disabled="key.removed" :aria-label="`Colonne ${String(position + 1)} de la clé ${key.name}`" @change="key.columns[at] = ($event.target as HTMLSelectElement).value">
                  <option v-for="column in keyChoices(key, key.columns[at] ?? null)" :key="column.uid" :value="column.uid" :selected="column.uid === key.columns[at]">{{ column.name }}</option>
                </select>
              </td>
              <td class="cols">
                <input class="field mono" :class="{ edited: edited(markOfKey(key), 'reference') }" :value="key.referencedColumns[at] ?? ''" :disabled="key.removed" placeholder="colonne référencée" :aria-label="`Colonne référencée ${String(position + 1)} de la clé ${key.name}`" spellcheck="false" autocomplete="off" @input="setPairedReference(key, at, ($event.target as HTMLInputElement).value)">
              </td>
              <td class="moves">
                <button type="button" class="step" :disabled="position === 0 || key.removed" :aria-label="`Monter ${columnName(key.columns[at] ?? '')} dans la clé ${key.name}`" @click="movePair(key, at, -1)">↑</button>
                <button type="button" class="step" :disabled="position === pairsOf(key).length - 1 || key.removed" :aria-label="`Descendre ${columnName(key.columns[at] ?? '')} dans la clé ${key.name}`" @click="movePair(key, at, 1)">↓</button>
                <button type="button" class="x" :disabled="key.removed" :aria-label="`Ôter ${columnName(key.columns[at] ?? '')} de la clé ${key.name}`" @click="dropPair(key, at)">✕</button>
              </td>
              <template v-if="position === 0">
                <td class="unique-cell" :rowspan="pairsOf(key).length + 1"><input v-model="key.referencedTable" class="field mono" :class="{ edited: edited(markOfKey(key), 'reference') }" :list="tableListId" :disabled="key.removed" placeholder="table référencée" aria-label="Table référencée" spellcheck="false" autocomplete="off"></td>
                <td class="unique-cell" :rowspan="pairsOf(key).length + 1"><select v-model="key.onDelete" class="field" :class="{ edited: edited(markOfKey(key), 'onDelete') }" :disabled="key.removed" aria-label="À la suppression"><option v-for="action in ACTIONS" :key="action.value" :value="action.value">{{ action.label }}</option></select></td>
                <td class="unique-cell" :rowspan="pairsOf(key).length + 1"><select v-model="key.onUpdate" class="field" :class="{ edited: edited(markOfKey(key), 'onUpdate') }" :disabled="key.removed" aria-label="À la mise à jour"><option v-for="action in ACTIONS" :key="action.value" :value="action.value">{{ action.label }}</option></select></td>
                <td class="act" :rowspan="pairsOf(key).length + 1">
                  <AppButton v-if="key.removed" variant="ghost" small @click="restore(key)">Restaurer</AppButton>
                  <button v-else type="button" class="x" :aria-label="`Retirer la clé étrangère ${key.name}`" @click="removeForeignKey(draft, key)">✕</button>
                </td>
              </template>
            </tr>
            <tr class="add-row">
              <template v-if="pairsOf(key).length === 0">
                <td class="name-cell"><input v-model="key.name" class="field mono" aria-label="Nom de la clé étrangère" spellcheck="false" autocomplete="off"></td>
              </template>
              <td class="rank-cell" />
              <td class="cols">
                <select class="field" :disabled="key.removed || keyChoices(key, null).length === 0" :aria-label="`Ajouter une colonne à la clé ${key.name}`" @change="addPair(key, $event)">
                  <option value="">+ Ajouter une colonne…</option>
                  <option v-for="column in keyChoices(key, null)" :key="column.uid" :value="column.uid">{{ column.name }}</option>
                </select>
              </td>
              <td class="cols" />
              <td class="moves" />
              <template v-if="pairsOf(key).length === 0">
                <td class="unique-cell"><input v-model="key.referencedTable" class="field mono" :list="tableListId" placeholder="table référencée" aria-label="Table référencée" spellcheck="false" autocomplete="off"></td>
                <td class="unique-cell"><select v-model="key.onDelete" class="field" aria-label="À la suppression"><option v-for="action in ACTIONS" :key="action.value" :value="action.value">{{ action.label }}</option></select></td>
                <td class="unique-cell"><select v-model="key.onUpdate" class="field" aria-label="À la mise à jour"><option v-for="action in ACTIONS" :key="action.value" :value="action.value">{{ action.label }}</option></select></td>
                <td class="act">
                  <AppButton v-if="key.removed" variant="ghost" small @click="restore(key)">Restaurer</AppButton>
                  <button v-else type="button" class="x" :aria-label="`Retirer la clé étrangère ${key.name}`" @click="removeForeignKey(draft, key)">✕</button>
                </td>
              </template>
            </tr>
          </tbody>
        </table>
      </div>
      <div><AppButton variant="ghost" small @click="addForeignKey(draft)">+ Ajouter une clé étrangère</AppButton></div>
      <datalist :id="tableListId"><option v-for="name in tableNames" :key="name" :value="name" /></datalist>
    </section>

    <section class="danger-zone">
      <h2>Supprimer la table</h2>
      <p class="dim">Supprime la table et toutes ses données de la base. Une confirmation vous montrera l’instruction avant de l’exécuter.</p>
      <div><AppButton variant="danger" small @click="askDrop">Supprimer la table…</AppButton></div>
    </section>

    <ConfirmDialog
      v-if="confirming"
      title="Confirmer la modification de la structure"
      message="Ces opérations détruisent des données ou une structure et ne peuvent pas être annulées :"
      confirm-label="Appliquer"
      danger
      :busy="applying"
      @confirm="apply(true)"
      @cancel="confirming = false"
    >
      <ul class="lost"><li v-for="statement in destructive" :key="statement.sql">{{ describeStatement(statement.operation, statement.subject) }}</li></ul>
    </ConfirmDialog>

    <ConfirmDialog
      v-if="dropping"
      :title="`Supprimer la table « ${table.name} » ?`"
      message="La table et toutes ses données seront supprimées de la base. Cette action est définitive."
      confirm-label="Supprimer la table"
      danger
      :busy="applying"
      @confirm="confirmDrop"
      @cancel="dropping = null"
    >
      <ul class="sql"><li v-for="statement in dropping.statements" :key="statement.sql" class="destructive"><code>{{ statement.sql }};</code></li></ul>
    </ConfirmDialog>
  </div>
</template>

<style scoped>
.editor { display: flex; flex-direction: column; gap: 26px; min-width: 0; }
section { display: flex; flex-direction: column; gap: 10px; }
h2 { font-size: 22px; font-weight: 600; }
.dim, .none { color: var(--mut); }
.mono { font-family: var(--font-mono); font-size: 12.5px; }
.changes { position: sticky; top: 0; z-index: 5; display: flex; flex-direction: column; gap: 10px; padding: 14px 16px; border-radius: 8px; border: 1px solid var(--acc-line); background: var(--acc-bg); box-shadow: 0 6px 18px rgba(0, 0, 0, 0.18); }
.changes .top { display: flex; flex-wrap: wrap; align-items: baseline; gap: 6px 14px; color: var(--fg); }
.mode, .hint { font-size: 12px; color: var(--mut); }
.sql { list-style: none; margin: 0; padding: 10px 12px; border-radius: var(--radius); background: var(--s1); border: 1px solid var(--line); display: flex; flex-direction: column; gap: 6px; max-height: 220px; overflow: auto; }
.sql li { display: flex; align-items: baseline; gap: 10px; }
.sql code { font: 400 12.5px/1.5 var(--font-mono); white-space: pre-wrap; overflow-wrap: anywhere; }
.sql li.destructive code { color: var(--bad); }
.buttons { display: flex; justify-content: flex-end; gap: 10px; flex-wrap: wrap; }
.alert { padding: 8px 10px; border-radius: var(--radius); background: var(--bad-bg); border: 1px solid var(--bad-line); color: var(--bad); font-size: 13px; }
.alert p { margin: 0 0 4px; }
.lock-check { padding: 8px 10px; border-radius: var(--radius); border: 1px solid var(--line); color: var(--mut); font-size: 13px; }
.lock-check.held { border-color: var(--sec-line); background: var(--sec-bg); color: var(--sec-ink); }
.lock-check p { margin: 0 0 4px; }
.lock-check ul { margin: 0 0 4px 18px; padding: 0; }
.alert ul { margin: 0 0 4px 18px; padding: 0; }
.link { background: none; border: 0; padding: 0; color: var(--acc); text-decoration: underline; cursor: pointer; font: inherit; }
.name { flex-direction: row; align-items: center; flex-wrap: wrap; gap: 10px; }
.name label { font-size: 13px; color: var(--mut); }
.field { height: 30px; padding: 0 8px; border-radius: 5px; border: 1px solid var(--line); background: var(--s2); color: var(--fg); font: inherit; min-width: 0; width: 100%; cursor: text; transition: border-color 0.12s, background 0.12s; }
select.field { cursor: pointer; }
.field:hover:not(:disabled) { border-color: var(--acc-line); }
.field:focus { border-color: var(--acc); background: var(--s1); outline: 2px solid var(--acc-bg); outline-offset: 0; }
.field.edited, input.edited { border-color: var(--sec-line); background: var(--sec-bg); }
input[type='checkbox'] { cursor: pointer; }
tbody tr:hover:not([data-status='removed']) > td { background: color-mix(in srgb, var(--acc) 5%, transparent); }
.step, .x { opacity: 0.75; }
tr:hover .step:not(:disabled), tr:hover .x:not(:disabled), .step:focus-visible, .x:focus-visible { opacity: 1; }
.field:disabled { opacity: 0.6; }
.narrow { width: 80px; }
.box { border: 1px solid var(--line); border-radius: var(--radius-lg); overflow-x: auto; background: var(--s1); }
table { border-collapse: collapse; width: 100%; font-size: 13px; }
th { text-align: left; font: 500 11px var(--font-mono); letter-spacing: 0.06em; text-transform: uppercase; color: var(--mut); padding: 9px 10px; background: var(--s2); border-bottom: 1px solid var(--line); white-space: nowrap; }
td { padding: 5px 6px; border-bottom: 1px solid var(--line-soft); }
tr:last-child td { border-bottom: 0; }
td.act { white-space: nowrap; text-align: right; }
tr[data-status='new'], li[data-status='new'] { background: var(--ok-bg); box-shadow: inset 3px 0 0 var(--ok); }
tr[data-status='changed'], li[data-status='changed'] { box-shadow: inset 3px 0 0 var(--sec); }
tr[data-status='removed'], li[data-status='removed'] { background: var(--bad-bg); box-shadow: inset 3px 0 0 var(--bad); }
tr[data-status='removed'] .field, li[data-status='removed'] .field { text-decoration: line-through; }
.x { width: 26px; height: 26px; border-radius: 5px; border: 1px solid transparent; background: transparent; color: var(--mut); cursor: pointer; }
.x:hover { color: var(--bad); border-color: var(--bad-line); }
.rows-table tbody { border-top: 2px solid var(--line); }
.rows-table tbody:first-of-type { border-top: 0; }
.rows-table td { vertical-align: middle; }
.rows-table .name-cell, .rows-table .type, .rows-table .unique-cell, .rows-table .act { vertical-align: top; padding-top: 8px; border-right: 1px solid var(--line-soft); }
.rows-table .type { white-space: nowrap; }
.rows-table .type .field { width: auto; margin-top: 6px; display: block; }
.rows-table td.cols, .rows-table td.rank-cell, .rows-table td.moves { border-bottom: 0; }
.rows-table td.rank-cell { width: 34px; text-align: center; }
.rows-table td.size-cell { vertical-align: top; padding-top: 16px; white-space: nowrap; color: var(--mut); font: 400 12px var(--font-mono); text-align: right; }
.rows-table th.rank-h { text-align: center; }
.rows-table td.moves { white-space: nowrap; }
.rows-table td.moves > * { margin-right: 4px; }
.rows-table td.cols.edited, .rows-table td.rank-cell.edited { background: var(--sec-bg); }
.rows-table .col-row td.cols .field, .rows-table .add-row td.cols .field { width: 100%; min-width: 160px; }
.fk-table .name-cell { min-width: 230px; }
.rows-table .rank { display: inline-flex; justify-content: center; align-items: center; width: 20px; height: 20px; border-radius: 10px; background: var(--s3); color: var(--mut); font: 500 11px var(--font-mono); }
.step { width: 24px; height: 24px; border-radius: 5px; border: 1px solid var(--line); background: transparent; color: var(--mut); cursor: pointer; }
.step:hover:not(:disabled) { color: var(--acc); border-color: var(--acc-line); }
.step:disabled { opacity: 0.35; cursor: default; }
.rows-table tbody[data-status='new'] { background: var(--ok-bg); box-shadow: inset 3px 0 0 var(--ok); }
.rows-table tbody[data-status='changed'] { box-shadow: inset 3px 0 0 var(--sec); }
.rows-table tbody[data-status='removed'] { background: var(--bad-bg); box-shadow: inset 3px 0 0 var(--bad); }
.rows-table tbody[data-status='removed'] .field { text-decoration: line-through; }
.check { display: inline-flex; align-items: center; gap: 6px; font-size: 12px; color: var(--mut); }
.tag { display: inline-flex; align-items: center; height: 20px; padding: 0 8px; border-radius: 4px; font: 500 11px var(--font-mono); border: 1px solid var(--line); color: var(--mut); }
.tag.pk { color: var(--sec); border-color: var(--sec-line); background: var(--sec-bg); }
.tag.danger { color: var(--bad); border-color: var(--bad-line); background: var(--bad-bg); }
.danger-zone { padding-top: 6px; border-top: 1px solid var(--line); }
.lost { margin: 0; padding-left: 18px; color: var(--fg); font-size: 13px; display: flex; flex-direction: column; gap: 4px; }
</style>
