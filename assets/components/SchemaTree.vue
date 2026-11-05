<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import type { TableSummary } from '../api/types'
import { formatEstimate } from '../utils/format'

const props = defineProps<{ connectionId: string; tables: TableSummary[]; total?: number | null; hasMore?: boolean; loading?: boolean; search?: string; current?: string; error?: string | null }>()
const emit = defineEmits<{ search: [query: string]; more: [] }>()
const filter = ref(props.search ?? '')
const number = new Intl.NumberFormat('fr-FR')

// La recherche est faite par le moteur sur toutes les tables, pas sur les seules tables chargées : on attend une pause de frappe.
let timer: ReturnType<typeof setTimeout> | undefined
watch(filter, (value) => {
  clearTimeout(timer)
  timer = setTimeout(() => emit('search', value.trim()), 300)
})
onBeforeUnmount(() => clearTimeout(timer))

const tableNodes = computed(() => props.tables.filter((table) => table.kind === 'table'))
const viewNodes = computed(() => props.tables.filter((table) => table.kind !== 'table'))
const counter = computed(() => (props.total === null || props.total === undefined ? number.format(props.tables.length) : `${number.format(props.tables.length)} / ${number.format(props.total)}`))
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
    <p v-else-if="props.tables.length === 0 && !loading" class="note">{{ filter.trim() !== '' || (search ?? '') !== '' ? 'Aucune table ne correspond à la recherche.' : 'Aucune table dans cette base.' }}</p>

    <template v-else>
      <p class="counter">{{ counter }}</p>
      <p class="group">Tables · {{ tableNodes.length }}</p>
      <RouterLink v-for="table in tableNodes" :key="table.name" class="node" :class="{ cur: table.name === current }" :aria-current="table.name === current ? 'page' : undefined" :to="{ name: 'table', params: { id: connectionId, name: table.name } }">
        <span class="label"><span class="icon table" aria-hidden="true">▤</span><span>{{ table.name }}</span></span>
        <small v-if="table.estimatedRows !== null">{{ formatEstimate(table.estimatedRows) }}</small>
      </RouterLink>
      <template v-if="viewNodes.length > 0">
        <p class="group">Vues · {{ viewNodes.length }}</p>
        <RouterLink v-for="view in viewNodes" :key="view.name" class="node" :class="{ cur: view.name === current }" :aria-current="view.name === current ? 'page' : undefined" :to="{ name: 'table', params: { id: connectionId, name: view.name } }">
          <span class="label"><span class="icon view" aria-hidden="true">◍</span><span>{{ view.name }}</span></span>
          <small>vue</small>
        </RouterLink>
      </template>
      <button v-if="hasMore" type="button" class="more" :disabled="loading" @click="emit('more')">{{ loading ? 'Chargement…' : 'Charger les 100 suivantes' }}</button>
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
.counter { padding: 0 12px; font: 500 11px var(--font-mono); letter-spacing: 0.04em; color: var(--mut); }
.label { display: inline-flex; align-items: center; gap: 8px; min-width: 0; }
.icon { font-size: 11px; color: var(--mut); }
.icon.view { color: var(--acc); }
.more { margin: 8px 4px 0; height: 34px; border-radius: var(--radius); border: 1px solid var(--line); background: var(--s2); color: var(--fg); font: 400 13px var(--font-ui); cursor: pointer; }
.more:disabled { opacity: 0.6; cursor: default; }
.note { padding: 14px 12px 0; font-size: 11px; color: var(--mut); }
.error { margin: 4px; padding: 10px 12px; border-radius: var(--radius); background: var(--bad-bg); border: 1px solid var(--bad-line); color: var(--bad); font-size: 13px; }
</style>
