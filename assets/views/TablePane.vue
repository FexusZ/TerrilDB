<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { messageFor } from '../api/errors'
import type { TableDetail } from '../api/types'
import AppButton from '../components/AppButton.vue'
import TableEditor from '../components/TableEditor.vue'
import TableStructure from '../components/TableStructure.vue'
import { useConnectionsStore } from '../stores/connections'
import { useTreeStore } from '../stores/tree'
import { useWorkspaceStore } from '../stores/workspace'
import { formatEstimate } from '../utils/format'
import { previewQuery } from '../utils/sql'

const props = defineProps<{ id: string; name: string }>()
const workspace = useWorkspaceStore()
const connections = useConnectionsStore()
const router = useRouter()
const tree = useTreeStore()

const table = ref<TableDetail | null>(null)
const error = ref<string | null>(null)

const KINDS = { table: 'table', view: 'vue', materialized_view: 'vue matérialisée' } as const
const rows = computed(() => (table.value === null || table.value.estimatedRows === null ? '' : `${formatEstimate(table.value.estimatedRows)} lignes`))

watch(
  () => props.name,
  async (name) => {
    table.value = null
    error.value = null
    try {
      table.value = await workspace.describe(name)
    } catch (failure) {
      error.value = messageFor(failure)
    }
  },
  { immediate: true },
)

const engine = computed(() => connections.byId(props.id)?.engine ?? 'postgresql')
const scope = computed(() => ({ database: workspace.database, schema: workspace.schema }))

// Une modification de structure a été appliquée : les listes de tables (panneau, autocomplétion, arbre) et la table elle-même sont à relire.
async function onApplied(next: TableDetail | null, previousName: string): Promise<void> {
  await Promise.all([workspace.reloadTables(), tree.refreshTables(props.id)])
  if (next === null) {
    await router.push({ name: 'workspace', params: { id: props.id } })

    return
  }
  table.value = next
  if (next.name !== previousName) {
    await router.replace({ name: 'table', params: { id: props.id, name: next.name }, query: router.currentRoute.value.query })
  }
}

async function reload(): Promise<void> {
  await workspace.reloadTables()
  error.value = null
  try {
    table.value = await workspace.describe(props.name)
  } catch (failure) {
    error.value = messageFor(failure)
  }
}

async function preview(): Promise<void> {
  const engine = connections.byId(props.id)?.engine ?? 'postgresql'
  const tab = workspace.addTab(previewQuery(engine, props.name))
  await router.push({ name: 'workspace', params: { id: props.id } })
  await workspace.run(tab)
}
</script>

<template>
  <section class="pane">
    <p v-if="error" class="error" role="alert">{{ error }}</p>
    <template v-else-if="table">
      <div class="head">
        <div class="id">
          <h1>{{ table.name }}</h1>
          <span class="chip">{{ KINDS[table.kind] }}</span>
          <span v-if="rows" class="chip">{{ rows }}</span>
        </div>
        <AppButton class="preview" small @click="preview">Voir les données</AppButton>
      </div>
      <div class="body">
        <TableEditor v-if="table.kind === 'table'" :connection-id="id" :engine="engine" :table="table" :scope="scope" @applied="onApplied" @reload="reload" />
        <TableStructure v-else :connection-id="id" :table="table" />
      </div>
    </template>
    <p v-else class="loading" role="status">Chargement…</p>
  </section>
</template>

<style scoped>
.pane { min-width: 0; overflow: auto; flex: 1; }
.head { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; padding: 22px 28px 14px; border-bottom: 1px solid var(--line); }
.id { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
h1 { font: 600 22px var(--font-mono); letter-spacing: -0.01em; }
.chip { display: inline-flex; align-items: center; height: 24px; padding: 0 10px; border-radius: 12px; border: 1px solid var(--line); font-size: 12px; color: var(--mut); }
.body { padding: 24px 28px 48px; }
.loading { padding: 24px 28px; color: var(--mut); }
.error { margin: 24px 28px; padding: 10px 12px; border-radius: var(--radius); background: var(--bad-bg); border: 1px solid var(--bad-line); color: var(--bad); font-size: 13px; }
</style>
