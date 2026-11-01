<script setup lang="ts">
import { computed, ref } from 'vue'
import type { TableSummary } from '../api/types'
import { formatEstimate } from '../utils/format'

const props = defineProps<{ connectionId: string; tables: TableSummary[]; current?: string; error?: string | null }>()
const filter = ref('')

const matching = computed(() => props.tables.filter((table) => table.name.toLowerCase().includes(filter.value.trim().toLowerCase())))
const tableNodes = computed(() => matching.value.filter((table) => table.kind === 'table'))
const viewNodes = computed(() => matching.value.filter((table) => table.kind !== 'table'))
</script>

<template>
  <nav class="tree" aria-label="Schéma">
    <div class="search">
      <label class="sr-only" for="filter">Filtrer les tables</label>
      <input id="filter" v-model="filter" type="search" placeholder="Filtrer les tables">
    </div>

    <div class="tools">
      <RouterLink class="audit" :to="{ name: 'audit', params: { id: connectionId } }">Audit du schéma</RouterLink>
      <RouterLink class="diagram" :to="{ name: 'diagram', params: { id: connectionId } }">Diagramme</RouterLink>
    </div>

    <p v-if="error" class="error" role="alert">{{ error }}</p>
    <p v-else-if="props.tables.length === 0" class="note">Aucune table dans cette base.</p>

    <template v-else>
      <p class="group">Tables · {{ tableNodes.length }}</p>
      <RouterLink v-for="table in tableNodes" :key="table.name" class="node" :class="{ cur: table.name === current }" :aria-current="table.name === current ? 'page' : undefined" :to="{ name: 'table', params: { id: connectionId, name: table.name } }">
        <span>{{ table.name }}</span>
        <small v-if="table.estimatedRows !== null">{{ formatEstimate(table.estimatedRows) }}</small>
      </RouterLink>
      <template v-if="viewNodes.length > 0">
        <p class="group">Vues · {{ viewNodes.length }}</p>
        <RouterLink v-for="view in viewNodes" :key="view.name" class="node" :class="{ cur: view.name === current }" :aria-current="view.name === current ? 'page' : undefined" :to="{ name: 'table', params: { id: connectionId, name: view.name } }">
          <span>{{ view.name }}</span>
          <small>vue</small>
        </RouterLink>
      </template>
      <p class="note">Les nombres de lignes sont des estimations du moteur.</p>
    </template>
  </nav>
</template>

<style scoped>
.tree { display: flex; flex-direction: column; gap: 2px; padding: 14px 8px; }
.search { padding: 0 4px 8px; }
input { width: 100%; height: 34px; padding: 0 10px; border-radius: var(--radius); border: 1px solid var(--line); background: var(--s1); color: var(--fg); font: 400 13px var(--font-ui); }
.tools { display: flex; gap: 6px; margin: 0 4px 8px; }
.audit, .diagram { flex: 1; padding: 8px 12px; border-radius: 5px; border: 1px solid var(--line); color: var(--acc); font-size: 13px; text-align: center; text-decoration: none; }
.audit:hover, .diagram:hover { background: var(--s2); }
.group { font: 500 11px var(--font-mono); letter-spacing: 0.08em; text-transform: uppercase; color: var(--mut); padding: 14px 12px 6px; }
.node { display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 6px 12px; border-radius: 5px; color: var(--fg); text-decoration: none; font-size: 13px; }
.node:hover { background: var(--s2); }
.node.cur { background: var(--acc-bg); color: var(--acc); }
small { font: 400 11px var(--font-mono); color: var(--mut); }
.note { padding: 14px 12px 0; font-size: 11px; color: var(--mut); }
.error { margin: 4px; padding: 10px 12px; border-radius: var(--radius); background: var(--bad-bg); border: 1px solid var(--bad-line); color: var(--bad); font-size: 13px; }
</style>
