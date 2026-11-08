<script setup lang="ts">
import { computed, watch } from 'vue'
import { reasonText } from '../api/errors'
import AppButton from '../components/AppButton.vue'
import ConfirmDialog from '../components/ConfirmDialog.vue'
import ResultTable from '../components/ResultTable.vue'
import SqlEditor from '../components/SqlEditor.vue'
import { useConnectionsStore } from '../stores/connections'
import { useWorkspaceStore } from '../stores/workspace'
import { previewQuery } from '../utils/sql'

const props = defineProps<{ id: string; name: string }>()
const workspace = useWorkspaceStore()
const connections = useConnectionsStore()

const dialect = computed(() => connections.byId(props.id)?.engine ?? 'postgresql')
const tab = computed(() => workspace.tabs.find((candidate) => candidate.table === props.name))
const exportable = computed(() => tab.value?.result != null && tab.value.result.affectedRows === null && !tab.value.running && !tab.value.exporting)
const tableNames = computed(() => [...new Set([...workspace.tables.map((table) => table.name), ...workspace.completionTables])])

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
        <AppButton class="run" small variant="primary" :busy="tab?.running" @click="tab && workspace.run(tab.id)">Exécuter</AppButton>
        <span class="shortcut">Ctrl + Entrée</span>
        <AppButton class="export-csv" small :disabled="!exportable" :busy="tab?.exporting" @click="tab && workspace.exportTab(tab.id, 'csv')">Exporter CSV</AppButton>
        <AppButton class="export-json" small :disabled="!exportable" @click="tab && workspace.exportTab(tab.id, 'json')">JSON</AppButton>
      </div>
    </div>

    <template v-if="tab">
      <div class="editor">
        <SqlEditor :model-value="tab.sql" :dialect="dialect" :tables="tableNames" @update:model-value="workspace.setSql(tab.id, $event)" @run="workspace.run(tab.id)" />
      </div>
      <p v-if="tab.exportNotice" class="notice" role="status">{{ tab.exportNotice }}</p>
      <div class="output">
        <p v-if="tab.running" class="state" role="status">Exécution…</p>
        <p v-else-if="tab.error" class="error" role="alert">{{ tab.error }}</p>
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
.state { padding: 24px 28px; color: var(--mut); }
.error { margin: 16px 28px; padding: 10px 12px; border-radius: var(--radius); background: var(--bad-bg); border: 1px solid var(--bad-line); color: var(--bad); font: 400 13px var(--font-mono); white-space: pre-wrap; }
</style>
