<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { applyData, previewData, type DataPreview, type DataWarning } from '../api/data'
import { messageFor, reasonText } from '../api/errors'
import type { TableDetail } from '../api/types'
import AppButton from '../components/AppButton.vue'
import ConfirmDialog from '../components/ConfirmDialog.vue'
import DataGrid from '../components/DataGrid.vue'
import ResultTable from '../components/ResultTable.vue'
import SqlEditor from '../components/SqlEditor.vue'
import { useConnectionsStore } from '../stores/connections'
import { useWorkspaceStore } from '../stores/workspace'
import { buildPayload, editability, emptyDraft, pendingCount, setCell, type DataDraft, type Typed } from '../utils/dataDraft'
import { isPlainReadOf, previewQuery } from '../utils/sql'

const props = defineProps<{ id: string; name: string }>()
const workspace = useWorkspaceStore()
const connections = useConnectionsStore()

const dialect = computed(() => connections.byId(props.id)?.engine ?? 'postgresql')
const tab = computed(() => workspace.tabs.find((candidate) => candidate.table === props.name))
const exportable = computed(() => tab.value?.result != null && tab.value.result.affectedRows === null && !tab.value.running && !tab.value.exporting)
const tableNames = computed(() => [...new Set([...workspace.tables.map((table) => table.name), ...workspace.completionTables])])
const scope = computed(() => ({ database: workspace.database, schema: workspace.schema }))

// Chaque table garde son onglet : la première visite lit les 100 premières lignes, les suivantes retrouvent le SQL tel qu'il a été modifié.
watch(
  () => props.name,
  async (name) => {
    const { id, created } = workspace.openTableData(name, previewQuery(dialect.value, name))
    if (created) {
      await workspace.run(id)
    }
  },
  { immediate: true },
)

const table = ref<TableDetail | null>(null)
watch(
  () => props.name,
  async (name) => {
    table.value = null
    try {
      table.value = await workspace.describe(name)
    } catch {
      // La structure est facultative ici : sans elle, les données restent en lecture seule.
    }
  },
  { immediate: true },
)

const verdict = computed(() => {
  const base = editability(table.value, tab.value?.result ?? null)
  if (base.editable && !isPlainReadOf(tab.value?.lastSql ?? '', props.name)) {
    return { editable: false, reason: 'Lecture seule : seule une lecture simple de la table (SELECT … FROM table, avec WHERE, ORDER BY ou LIMIT) se modifie ligne à ligne.' }
  }

  return base
})
const editable = computed(() => verdict.value.editable)

const draft = ref<DataDraft>(emptyDraft())
const pending = computed(() => pendingCount(draft.value))
const review = ref<DataPreview | null>(null)
const reviewing = ref(false)
/** Le moteur de stockage de la table n'annule pas : la promesse « tout ou rien » serait fausse. */
const rollbackless = computed(() => review.value?.warnings.some((w) => w.code === 'not_transactional') ?? false)

function warningText(warning: DataWarning): string {
  if (warning.code === 'not_transactional') {
    return `Le moteur de stockage de cette table (${String(warning.value)}) n’annule pas : si une instruction échoue, celles déjà appliquées le restent.`
  }
  const count = Number(warning.value)
  return `Cette table a ${count} ${count > 1 ? 'déclencheurs' : 'déclencheur'} : l’écriture peut avoir d’autres effets.`
}
const applying = ref(false)
const problem = ref<string | null>(null)
const applied = ref<string | null>(null)

function reset(): void {
  draft.value = emptyDraft()
  review.value = null
  problem.value = null
}

// Un nouveau résultat (autre requête, autre table, relecture après application) repart d'un brouillon vide.
watch(() => [props.name, tab.value?.result], reset)
// Toute saisie rend caduc l'aperçu déjà calculé.
watch(draft, () => {
  review.value = null
  problem.value = null
  applied.value = null
}, { deep: true })

function run(): void {
  if (tab.value && pending.value === 0) {
    void workspace.run(tab.value.id)
  }
}

function edit(row: number, column: string, value: Typed): void {
  const read = tab.value?.result
  if (!read) {
    return
  }
  const original = read.rows[row]?.[read.columns.indexOf(column)]
  setCell(draft.value, read, row, column, value === '' && original === null ? null : value)
}

function toggleDelete(row: number): void {
  if (draft.value.deleted.has(row)) {
    draft.value.deleted.delete(row)
  } else {
    draft.value.deleted.add(row)
  }
}

function addRow(): void {
  draft.value.inserts.push(Object.fromEntries((tab.value?.result?.columns ?? []).map((name) => [name, ''])))
}

const payload = () => buildPayload(tab.value?.result ?? { columns: [], rows: [] }, draft.value)

async function check(): Promise<void> {
  reviewing.value = true
  problem.value = null
  try {
    review.value = await previewData(props.id, props.name, payload(), scope.value)
  } catch (failure) {
    problem.value = messageFor(failure)
  } finally {
    reviewing.value = false
  }
}

async function apply(): Promise<void> {
  if (review.value === null || !review.value.ok || applying.value) {
    return
  }
  const plan = review.value
  applying.value = true
  problem.value = null
  try {
    await applyData(props.id, props.name, payload(), plan.destructive, scope.value)
    reset()
    if (tab.value) {
      await workspace.run(tab.value.id)
    }
    applied.value = plan.asynchronous
      ? 'Changements envoyés. ClickHouse applique les modifications et suppressions en arrière-plan : elles n’apparaîtront pas tout de suite, relancez la lecture dans un instant.'
      : 'Changements appliqués.'
  } catch (failure) {
    problem.value = messageFor(failure)
  } finally {
    applying.value = false
  }
}

const matchedText = (matched: number | null): string => (matched === null ? '' : matched === 1 ? '1 ligne' : `${matched} lignes`)
</script>

<template>
  <section class="pane">
    <div class="head">
      <div class="id">
        <h1>{{ name }}</h1>
        <span class="chip">données</span>
        <span v-if="tab?.result" class="chip">{{ tab.result.rowCount }} {{ tab.result.rowCount > 1 ? 'lignes' : 'ligne' }}</span>
        <span v-if="tab?.result" class="chip">{{ tab.result.durationMs }} ms</span>
      </div>
      <div class="actions">
        <AppButton class="run" small variant="primary" :busy="tab?.running" :disabled="pending > 0" :title="pending > 0 ? 'Validez ou annulez les changements en attente avant de relire' : undefined" @click="run">Exécuter</AppButton>
        <span class="shortcut">Ctrl + Entrée</span>
        <AppButton class="export-csv" small :disabled="!exportable" :busy="tab?.exporting" @click="tab && workspace.exportTab(tab.id, 'csv')">Exporter CSV</AppButton>
        <AppButton class="export-json" small :disabled="!exportable" @click="tab && workspace.exportTab(tab.id, 'json')">JSON</AppButton>
      </div>
    </div>

    <template v-if="tab">
      <div class="editor">
        <SqlEditor :model-value="tab.sql" :dialect="dialect" :tables="tableNames" @update:model-value="pending === 0 && workspace.setSql(tab.id, $event)" @run="run" />
      </div>
      <p v-if="tab.exportNotice" class="notice" role="status">{{ tab.exportNotice }}</p>

      <aside v-if="pending > 0" class="changes" role="region" aria-label="Modifications en attente">
        <div class="top">
          <strong>{{ pending }} {{ pending > 1 ? 'changements' : 'changement' }} en attente</strong>
          <span v-if="review" class="mode">{{ review.transactional && !rollbackless ? 'Appliqués en une seule transaction : tout ou rien.' : 'Appliqués instruction par instruction : en cas d’erreur, les précédents restent en place.' }}</span>
        </div>
        <ul v-if="review" class="sql" aria-label="SQL qui sera exécuté">
          <li v-for="(statement, position) in review.statements" :key="position" :class="{ destructive: statement.destructive, missing: statement.matched !== null && statement.matched !== 1 }">
            <code>{{ statement.sql }};</code>
            <span v-if="statement.matched !== null" class="tag">{{ matchedText(statement.matched) }}</span>
            <span v-if="statement.destructive" class="tag danger">supprime</span>
          </li>
        </ul>
        <ul v-if="review && review.warnings.length > 0" class="warnings" role="status" aria-label="Avertissements">
          <li v-for="warning in review.warnings" :key="warning.code">{{ warningText(warning) }}</li>
        </ul>
        <p v-if="review && review.asynchronous" class="async" role="status">ClickHouse applique les modifications et suppressions en arrière-plan : elles n’apparaîtront pas tout de suite dans les données.</p>
        <p v-if="review && !review.ok" class="alert" role="alert">Une ligne a été modifiée ou supprimée par ailleurs, ou ne se distingue pas d’une autre : rechargez les données avant de continuer.</p>
        <p v-if="problem" class="alert" role="alert">{{ problem }}</p>
        <div class="buttons">
          <AppButton class="cancel-changes" variant="ghost" small :disabled="applying" @click="reset">Annuler</AppButton>
          <AppButton v-if="!review" class="review" variant="primary" small :busy="reviewing" @click="check">Vérifier les changements</AppButton>
          <AppButton v-else class="apply" variant="primary" small :busy="applying" :disabled="!review.ok" @click="apply">{{ review.destructive ? 'Supprimer et appliquer' : 'Appliquer' }}</AppButton>
        </div>
      </aside>
      <p v-else-if="applied" class="applied" role="status">{{ applied }}</p>
      <p v-if="verdict.reason" class="readonly" role="status">{{ verdict.reason }}</p>

      <div class="output">
        <p v-if="tab.running" class="state" role="status">Exécution…</p>
        <p v-else-if="tab.error" class="error" role="alert">{{ tab.error }}</p>
        <DataGrid
          v-else-if="tab.result && editable && table"
          :result="tab.result"
          :columns="table.columns"
          :draft="draft"
          @edit="edit"
          @toggle-delete="toggleDelete"
          @edit-insert="(index, column, value) => (draft.inserts[index] = { ...draft.inserts[index], [column]: value })"
          @remove-insert="(index) => draft.inserts.splice(index, 1)"
          @add-row="addRow"
        />
        <ResultTable v-else-if="tab.result" :result="tab.result" />
        <p v-else class="state">Exécutez la requête pour voir les données.</p>
      </div>
      <ConfirmDialog
        v-if="tab.pendingConfirmation"
        title="Confirmer cette instruction ?"
        :message="reasonText(tab.pendingConfirmation)"
        confirm-label="Exécuter quand même"
        danger
        @confirm="workspace.confirmPending(tab.id)"
        @cancel="workspace.cancelPending(tab.id)"
      />
    </template>
  </section>
</template>

<style scoped>
.pane { display: flex; flex-direction: column; min-width: 0; min-height: 0; flex: 1; }
.head { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; padding: 22px 28px 14px; border-bottom: 1px solid var(--line); }
.id { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
h1 { font: 600 36px/1.1 var(--font-display); letter-spacing: -0.02em; }
.chip { display: inline-flex; align-items: center; height: 24px; padding: 0 10px; border-radius: 12px; border: 1px solid var(--line); font-size: 12px; color: var(--mut); }
.actions { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
.shortcut { font: 400 11px var(--font-mono); color: var(--mut); margin-right: 8px; }
.editor { min-height: 150px; max-height: 32vh; border-bottom: 1px solid var(--line); display: flex; flex-direction: column; }
.editor > :deep(*) { flex: 1; }
.notice { padding: 8px 16px; background: var(--sec-bg); border-bottom: 1px solid var(--sec-line); color: var(--sec-ink); font-size: 13px; }
.output { flex: 1; min-height: 0; overflow: auto; }
.changes { position: sticky; top: 0; z-index: 5; display: flex; flex-direction: column; gap: 10px; margin: 12px 28px 0; padding: 14px 16px; border-radius: var(--radius-lg); border: 1px solid var(--acc-line); background: var(--acc-bg); }
.changes .top { display: flex; flex-wrap: wrap; align-items: baseline; gap: 6px 14px; color: var(--fg); }
.mode { font-size: 12px; color: var(--mut); }
.sql { list-style: none; margin: 0; padding: 10px 12px; border-radius: var(--radius); background: var(--s1); border: 1px solid var(--line); display: flex; flex-direction: column; gap: 6px; max-height: 220px; overflow: auto; }
.sql li { display: flex; align-items: baseline; gap: 10px; }
.sql code { font: 400 12.5px/1.5 var(--font-mono); white-space: pre-wrap; overflow-wrap: anywhere; }
.sql li.destructive code { color: var(--bad); }
.sql li.missing code { color: var(--bad); text-decoration: underline wavy; }
.tag { flex: none; padding: 1px 8px; border-radius: 10px; border: 1px solid var(--line); font-size: 11px; color: var(--mut); }
.tag.danger { border-color: var(--bad-line); color: var(--bad); }
.warnings { margin: 0; padding: 8px 10px 8px 26px; font-size: 13px; color: var(--sec-ink); border-radius: var(--radius); background: var(--sec-bg); border: 1px solid var(--sec-line); }
.async { font-size: 13px; color: var(--sec-ink); padding: 8px 10px; border-radius: var(--radius); background: var(--sec-bg); border: 1px solid var(--sec-line); }
.alert { padding: 8px 10px; border-radius: var(--radius); background: var(--bad-bg); border: 1px solid var(--bad-line); color: var(--bad); font-size: 13px; }
.buttons { display: flex; justify-content: flex-end; gap: 8px; }
.applied { margin: 12px 28px 0; padding: 10px 14px; border-radius: var(--radius-lg); background: var(--sec-bg); border: 1px solid var(--sec-line); color: var(--sec-ink); font-size: 13px; }
.readonly { margin: 10px 28px 0; font-size: 12px; color: var(--mut); }
.state { padding: 24px 28px; color: var(--mut); }
.error { margin: 16px 28px; padding: 10px 12px; border-radius: var(--radius); background: var(--bad-bg); border: 1px solid var(--bad-line); color: var(--bad); font: 400 13px var(--font-mono); white-space: pre-wrap; }
</style>
