<script setup lang="ts">
import { computed } from 'vue'
import { reasonText } from '../api/errors'
import AppButton from '../components/AppButton.vue'
import ConfirmDialog from '../components/ConfirmDialog.vue'
import ResultTable from '../components/ResultTable.vue'
import SqlEditor from '../components/SqlEditor.vue'
import { useConnectionsStore } from '../stores/connections'
import { useWorkspaceStore } from '../stores/workspace'

const workspace = useWorkspaceStore()
const connections = useConnectionsStore()

const tab = computed(() => workspace.activeTab)
const readOnly = computed(() => tab.value.mode === 'read_only')
const dialect = computed(() => connections.byId(workspace.connectionId ?? '')?.engine ?? 'postgresql')
const tableNames = computed(() => workspace.tables.map((table) => table.name))

function toggleMode(): void {
  workspace.setMode(tab.value.id, readOnly.value ? 'read_write' : 'read_only')
}
</script>

<template>
  <section class="pane">
    <div class="tabs" role="tablist" aria-label="Requêtes">
      <div v-for="item in workspace.tabs" :key="item.id" class="tab" :class="{ current: item.id === tab.id }">
        <button type="button" role="tab" class="label" :aria-selected="item.id === tab.id" @click="workspace.select(item.id)">{{ item.title }}</button>
        <button type="button" class="close-tab" :aria-label="`Fermer ${item.title}`" @click="workspace.closeTab(item.id)">×</button>
      </div>
      <button type="button" class="add-tab" aria-label="Nouvel onglet" @click="workspace.addTab()">+</button>
    </div>

    <div class="toolbar">
      <div class="run-group">
        <AppButton class="run" small variant="primary" :busy="tab.running" @click="workspace.run(tab.id)">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M6 4l14 8-14 8z" /></svg>
          Exécuter
        </AppButton>
        <span class="shortcut">Ctrl + Entrée</span>
      </div>
      <button type="button" role="switch" class="mode" :class="{ write: !readOnly }" :aria-checked="readOnly" @click="toggleMode">
        {{ readOnly ? 'Lecture seule' : 'Écriture activée' }}
      </button>
    </div>

    <div class="editor">
      <SqlEditor :model-value="tab.sql" :dialect="dialect" :tables="tableNames" @update:model-value="workspace.setSql(tab.id, $event)" @run="workspace.run(tab.id)" />
    </div>

    <div class="output">
      <p v-if="tab.running" class="state" role="status">Exécution…</p>
      <p v-else-if="tab.error" class="error" role="alert">{{ tab.error }}</p>
      <ResultTable v-else-if="tab.result" :result="tab.result" />
      <p v-else class="state">Exécutez une requête pour voir son résultat.</p>
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
  </section>
</template>

<style scoped>
.pane { display: flex; flex-direction: column; min-width: 0; min-height: 0; flex: 1; }
.tabs { display: flex; background: var(--s1); border-bottom: 1px solid var(--line); overflow-x: auto; }
.tab { display: flex; align-items: center; border-right: 1px solid var(--line); color: var(--mut); white-space: nowrap; }
.tab.current { background: var(--bg); color: var(--fg); box-shadow: inset 0 -2px 0 var(--acc); }
.label { height: 38px; padding: 0 6px 0 14px; background: none; border: 0; color: inherit; font: 400 13px var(--font-ui); cursor: pointer; }
.close-tab, .add-tab { background: none; border: 0; color: var(--mut); cursor: pointer; font-size: 16px; padding: 0 10px; height: 38px; }
.add-tab { margin-left: auto; border-left: 1px solid var(--line); }
.toolbar { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 10px; padding: 10px 16px; border-bottom: 1px solid var(--line); }
.run-group { display: flex; gap: 10px; align-items: center; }
.shortcut { font: 400 11px var(--font-mono); color: var(--mut); }
.mode { display: inline-flex; align-items: center; height: 24px; padding: 0 10px; border-radius: 12px; border: 1px solid var(--sec-line); background: var(--sec-bg); color: var(--sec); font: 400 12px var(--font-ui); cursor: pointer; }
.mode.write { border-color: var(--bad-line); background: var(--bad-bg); color: var(--bad); }
.editor { min-height: 230px; max-height: 40vh; border-bottom: 1px solid var(--line); display: flex; flex-direction: column; }
.editor > :deep(*) { flex: 1; }
.output { flex: 1; min-height: 0; overflow: auto; }
.state { padding: 24px 16px; color: var(--mut); }
.error { margin: 16px; padding: 10px 12px; border-radius: var(--radius); background: var(--bad-bg); border: 1px solid var(--bad-line); color: var(--bad); font: 400 13px var(--font-mono); white-space: pre-wrap; }
</style>
