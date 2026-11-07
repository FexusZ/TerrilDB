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
      <p v-if="draft.indexes.length === 0 && !features.primaryKey" class="none">Aucun index.</p>
      <ul v-else class="cards">
        <li v-if="features.primaryKey" class="card" data-kind="primary" :data-status="primaryMark.status">
          <span class="tag pk">clé primaire</span>
          <span v-if="draft.primaryOriginalName" class="mono dim">{{ draft.primaryOriginalName }}</span>
          <span v-if="draft.primary.length === 0" class="dim">Aucune clé primaire : choisissez des colonnes.</span>
          <div class="chips" role="group" aria-label="Colonnes de la clé primaire">
            <button v-for="column in liveColumns" :key="column.uid" type="button" class="chip" :class="{ on: isPrimary(column), edited: primaryMark.status === 'changed' && isPrimary(column) }" :aria-pressed="isPrimary(column)" @click="togglePrimary(draft, column)">{{ column.name || '…' }}<sup v-if="isPrimary(column) && draft.primary.length > 1"> {{ draft.primary.indexOf(column.uid) + 1 }}</sup></button>
          </div>
          <AppButton v-if="primaryMark.status === 'removed'" variant="ghost" small @click="restorePrimary(draft)">Restaurer</AppButton>
          <button v-else-if="draft.primary.length > 0" type="button" class="x" aria-label="Retirer la clé primaire" @click="removePrimary(draft)">✕</button>
        </li>
        <li v-for="index in draft.indexes" :key="index.uid" class="card" :data-status="markOfIndex(index).status">
          <template v-if="index.locked">
            <span class="mono">{{ index.name }}</span>
            <span class="mono dim">{{ index.columns.join(', ') }}</span>
            <span class="tag">clé de tri : lecture seule</span>
          </template>
          <template v-else>
            <input v-model="index.name" class="field mono" :class="{ edited: edited(markOfIndex(index), 'name') }" :disabled="index.removed || (!features.renameIndex && index.originalName !== null)" aria-label="Nom de l’index" spellcheck="false" autocomplete="off">
            <div class="chips" role="group" :aria-label="`Colonnes de l’index ${index.name}`">
              <button v-for="column in liveColumns" :key="column.uid" type="button" class="chip" :class="{ on: index.columns.includes(column.uid), edited: edited(markOfIndex(index), 'columns') }" :aria-pressed="index.columns.includes(column.uid)" :disabled="index.removed" @click="toggleIn(index.columns, column.uid)">{{ column.name || '…' }}</button>
            </div>
            <label v-if="features.uniqueIndex" class="check"><input v-model="index.unique" type="checkbox" :class="{ edited: edited(markOfIndex(index), 'unique') }" :disabled="index.removed"> unique</label>
            <template v-if="features.skipIndex && index.originalName === null">
              <select v-model="index.kind" class="field" aria-label="Type d’index de saut"><option :value="null">minmax</option><option v-for="kind in SKIP_KINDS.slice(1)" :key="kind" :value="kind">{{ kind }}</option></select>
              <input v-model.number="index.granularity" class="field narrow" type="number" min="1" placeholder="1" aria-label="Granularité">
            </template>
            <AppButton v-if="index.removed" variant="ghost" small @click="restore(index)">Restaurer</AppButton>
            <button v-else type="button" class="x" :aria-label="`Retirer l’index ${index.name}`" @click="removeIndex(draft, index)">✕</button>
          </template>
        </li>
      </ul>
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
