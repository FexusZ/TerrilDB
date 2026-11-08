<script setup lang="ts">
import { computed, onBeforeUnmount, ref, useId, watch } from 'vue'
import { ApiError } from '../api/client'
import { messageFor } from '../api/errors'
import { applyStructure, previewStructure, type StructureScope } from '../api/structure'
import { COLUMN_TYPES, ENGINE_STRUCTURE, type Engine, type ReferentialActionName, type StructurePreview, type TableDetail } from '../api/types'
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

function toggleIn(list: string[], value: string): void {
  const at = list.indexOf(value)
  if (at === -1) {
    list.push(value)
  } else {
    list.splice(at, 1)
  }
}

function setReferencedColumns(key: DraftForeignKey, text: string): void {
  key.referencedColumns = text.split(',').map((part) => part.trim()).filter((part) => part !== '')
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
    d.foreignKeys.some((key) => !key.removed && (nameless(key.name) || key.columns.length === 0 || nameless(key.referencedTable) || key.referencedColumns.length !== key.columns.length))
  )
})

const preview = ref<StructurePreview | null>(null)
const previewing = ref(false)
const problem = ref<string | null>(null)
const failure = ref<{ message: string; applied: string[] } | null>(null)
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
  if (error instanceof ApiError && error.code === 'schema_change_failed') {
    const applied = Array.isArray(error.details.applied) ? (error.details.applied as { operation: string; subject: string }[]).map((s) => describeStatement(s.operation, s.subject)) : []
    failure.value = { message: messageFor(error), applied }
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
      <p v-if="stale && !failure" class="alert" role="alert">La table a changé depuis son chargement. <button type="button" class="link" @click="emit('reload')">Recharger la table</button></p>
      <p v-if="problem && !(stale && !failure)" class="alert" role="alert">{{ problem }}</p>
      <div v-if="failure" class="alert" role="alert">
        <p>{{ failure.message }}</p>
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
        <table class="index-table">
          <thead>
            <tr><th>Nom</th><th>Type</th><th v-if="features.uniqueIndex">Unique</th><th class="rank-h">#</th><th>Colonne</th><th>Ordre</th><th><span class="sr-only">Actions</span></th></tr>
          </thead>
          <tbody v-for="row in indexRows" :key="row.id" :data-kind="row.kind" :data-status="row.mark.status">
            <template v-if="row.kind === 'locked'">
              <tr class="col-row">
                <td class="mono">{{ row.name }}</td>
                <td class="type"><span class="tag">clé de tri : lecture seule</span></td>
                <td v-if="features.uniqueIndex" />
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
      <ul v-else class="cards">
        <li v-for="key in draft.foreignKeys" :key="key.uid" class="card" :data-status="markOfKey(key).status">
          <input v-model="key.name" class="field mono" :class="{ edited: edited(markOfKey(key), 'name') }" :disabled="key.removed" aria-label="Nom de la clé étrangère" spellcheck="false" autocomplete="off">
          <div class="chips" role="group" :aria-label="`Colonnes de la clé ${key.name}`">
            <button v-for="column in liveColumns" :key="column.uid" type="button" class="chip" :class="{ on: key.columns.includes(column.uid), edited: edited(markOfKey(key), 'columns') }" :aria-pressed="key.columns.includes(column.uid)" :disabled="key.removed" @click="toggleIn(key.columns, column.uid)">{{ column.name || '…' }}</button>
          </div>
          <span class="dim">→</span>
          <input v-model="key.referencedTable" class="field mono" :class="{ edited: edited(markOfKey(key), 'reference') }" :list="tableListId" :disabled="key.removed" placeholder="table référencée" aria-label="Table référencée" spellcheck="false" autocomplete="off">
          <input class="field mono" :class="{ edited: edited(markOfKey(key), 'reference') }" :value="key.referencedColumns.join(', ')" :disabled="key.removed" placeholder="colonnes, séparées par des virgules" aria-label="Colonnes référencées" spellcheck="false" autocomplete="off" @input="setReferencedColumns(key, ($event.target as HTMLInputElement).value)">
          <label class="check">suppression <select v-model="key.onDelete" class="field" :class="{ edited: edited(markOfKey(key), 'onDelete') }" :disabled="key.removed" aria-label="À la suppression"><option v-for="action in ACTIONS" :key="action.value" :value="action.value">{{ action.label }}</option></select></label>
          <label class="check">mise à jour <select v-model="key.onUpdate" class="field" :class="{ edited: edited(markOfKey(key), 'onUpdate') }" :disabled="key.removed" aria-label="À la mise à jour"><option v-for="action in ACTIONS" :key="action.value" :value="action.value">{{ action.label }}</option></select></label>
          <AppButton v-if="key.removed" variant="ghost" small @click="restore(key)">Restaurer</AppButton>
          <button v-else type="button" class="x" :aria-label="`Retirer la clé étrangère ${key.name}`" @click="removeForeignKey(draft, key)">✕</button>
        </li>
      </ul>
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
h2 { font-size: 15px; font-weight: 600; }
.dim, .none { color: var(--mut); }
.mono { font-family: var(--font-mono); font-size: 12.5px; }
.changes { position: sticky; top: 0; z-index: 5; display: flex; flex-direction: column; gap: 10px; padding: 14px 16px; border-radius: 8px; border: 1px solid var(--sec-line); background: var(--sec-bg); box-shadow: 0 6px 18px rgba(0, 0, 0, 0.18); }
.changes .top { display: flex; flex-wrap: wrap; align-items: baseline; gap: 6px 14px; color: var(--sec-ink); }
.mode, .hint { font-size: 12px; color: var(--sec-ink); }
.sql { list-style: none; margin: 0; padding: 10px 12px; border-radius: var(--radius); background: var(--s1); border: 1px solid var(--line); display: flex; flex-direction: column; gap: 6px; max-height: 220px; overflow: auto; }
.sql li { display: flex; align-items: baseline; gap: 10px; }
.sql code { font: 400 12.5px/1.5 var(--font-mono); white-space: pre-wrap; overflow-wrap: anywhere; }
.sql li.destructive code { color: var(--bad); }
.buttons { display: flex; justify-content: flex-end; gap: 10px; flex-wrap: wrap; }
.alert { padding: 8px 10px; border-radius: var(--radius); background: var(--bad-bg); border: 1px solid var(--bad-line); color: var(--bad); font-size: 13px; }
.alert p { margin: 0 0 4px; }
.alert ul { margin: 0 0 4px 18px; padding: 0; }
.link { background: none; border: 0; padding: 0; color: var(--acc); text-decoration: underline; cursor: pointer; font: inherit; }
.name { flex-direction: row; align-items: center; flex-wrap: wrap; gap: 10px; }
.name label { font-size: 13px; color: var(--mut); }
.field { height: 30px; padding: 0 8px; border-radius: 5px; border: 1px solid transparent; background: transparent; color: var(--fg); font: inherit; min-width: 0; width: 100%; }
.field:hover { border-color: var(--line); }
.field:focus { border-color: var(--acc); background: var(--s1); outline: none; }
.field.edited, input.edited, .chip.edited { border-color: var(--sec-line); background: var(--sec-bg); }
.field:disabled { opacity: 0.6; }
.narrow { width: 80px; }
.box { border: 1px solid var(--line); border-radius: 8px; overflow-x: auto; background: var(--s1); }
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
.cards { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 8px; }
.card { display: flex; flex-wrap: wrap; align-items: center; gap: 8px 12px; padding: 8px 10px; border: 1px solid var(--line); border-radius: 8px; background: var(--s1); }
.card .field { width: auto; min-width: 130px; flex: 0 1 180px; }
.index-table tbody { border-top: 2px solid var(--line); }
.index-table tbody:first-of-type { border-top: 0; }
.index-table td { vertical-align: middle; }
.index-table .name-cell, .index-table .type, .index-table .unique-cell, .index-table .act { vertical-align: top; padding-top: 8px; border-right: 1px solid var(--line-soft); }
.index-table .type { white-space: nowrap; }
.index-table .type .field { width: auto; margin-top: 6px; display: block; }
.index-table td.cols, .index-table td.rank-cell, .index-table td.moves { border-bottom: 0; }
.index-table td.rank-cell { width: 34px; text-align: center; }
.index-table th.rank-h { text-align: center; }
.index-table td.moves { white-space: nowrap; }
.index-table td.moves > * { margin-right: 4px; }
.index-table td.cols.edited, .index-table td.rank-cell.edited { background: var(--sec-bg); }
.index-table .col-row td.cols .field, .index-table .add-row td.cols .field { width: 100%; min-width: 160px; border-color: var(--line); }
.index-table .rank { display: inline-flex; justify-content: center; align-items: center; width: 20px; height: 20px; border-radius: 10px; background: var(--s3); color: var(--mut); font: 500 11px var(--font-mono); }
.step { width: 24px; height: 24px; border-radius: 5px; border: 1px solid var(--line); background: transparent; color: var(--mut); cursor: pointer; }
.step:hover:not(:disabled) { color: var(--acc); border-color: var(--acc-line); }
.step:disabled { opacity: 0.35; cursor: default; }
.index-table tbody[data-status='new'] { background: var(--ok-bg); box-shadow: inset 3px 0 0 var(--ok); }
.index-table tbody[data-status='changed'] { box-shadow: inset 3px 0 0 var(--sec); }
.index-table tbody[data-status='removed'] { background: var(--bad-bg); box-shadow: inset 3px 0 0 var(--bad); }
.index-table tbody[data-status='removed'] .field { text-decoration: line-through; }
.chips { display: flex; flex-wrap: wrap; gap: 6px; }
.chip { height: 24px; padding: 0 10px; border-radius: 12px; border: 1px solid var(--line); background: transparent; color: var(--mut); font: 400 12px var(--font-mono); cursor: pointer; }
.chip.on { color: var(--acc); border-color: var(--acc-line); background: var(--acc-bg); }
.check { display: inline-flex; align-items: center; gap: 6px; font-size: 12px; color: var(--mut); }
.tag { display: inline-flex; align-items: center; height: 20px; padding: 0 8px; border-radius: 4px; font: 500 11px var(--font-mono); border: 1px solid var(--line); color: var(--mut); }
.tag.pk { color: var(--sec); border-color: var(--sec-line); background: var(--sec-bg); }
.tag.danger { color: var(--bad); border-color: var(--bad-line); background: var(--bad-bg); }
.danger-zone { padding-top: 6px; border-top: 1px solid var(--line); }
.lost { margin: 0; padding-left: 18px; color: var(--fg); font-size: 13px; display: flex; flex-direction: column; gap: 4px; }
</style>
