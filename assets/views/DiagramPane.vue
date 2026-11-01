<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { api } from '../api/client'
import { messageFor } from '../api/errors'
import type { SchemaDiagram } from '../api/types'
import { BOX_WIDTH, HEADER_HEIGHT, ROW_HEIGHT, layoutDiagram } from '../utils/erLayout'

const props = defineProps<{ id: string }>()
const router = useRouter()

const diagram = ref<SchemaDiagram | null>(null)
const error = ref<string | null>(null)

const layout = computed(() => (diagram.value === null ? null : layoutDiagram(diagram.value.tables, diagram.value.relations)))
const relations = computed(() => {
  const columns = new Map((diagram.value?.relations ?? []).map((relation) => [relation.name + relation.table, relation]))

  return (layout.value?.relations ?? []).map((edge) => {
    const relation = columns.get(edge.name + edge.from)

    return { ...edge, label: relation ? `${relation.table}.${relation.columns.join(', ')} → ${relation.referencedTable}.${relation.referencedColumns.join(', ')}` : edge.name }
  })
})

onMounted(async () => {
  try {
    diagram.value = await api<SchemaDiagram>('GET', `/web/connections/${props.id}/diagram`)
  } catch (failure) {
    error.value = messageFor(failure)
  }
})

function open(name: string): void {
  void router.push({ name: 'table', params: { id: props.id, name } })
}
</script>

<template>
  <section class="pane">
    <div class="head">
      <h1>Diagramme du schéma</h1>
      <p v-if="diagram" class="meta">{{ diagram.tables.length }} {{ diagram.tables.length > 1 ? 'tables' : 'table' }}, {{ diagram.relations.length }} {{ diagram.relations.length > 1 ? 'relations' : 'relation' }} (clés étrangères). Cliquez sur un nom pour ouvrir la table.</p>
    </div>

    <p v-if="error" class="error" role="alert">{{ error }}</p>
    <p v-else-if="!diagram || !layout" class="meta" role="status">Lecture du schéma…</p>
    <template v-else>
      <p v-if="diagram.truncated" class="truncated" role="status">Le diagramme s’est arrêté aux 300 premières tables.</p>
      <p v-if="diagram.tables.length === 0" class="meta">Aucune table à dessiner.</p>
      <div v-else class="canvas">
        <svg :width="layout.width" :height="layout.height" :viewBox="`0 0 ${layout.width} ${layout.height}`" role="img" aria-label="Diagramme entité-relation du schéma">
          <path v-for="edge in relations" :key="edge.name + edge.from" class="relation" :d="edge.path" fill="none">
            <title>{{ edge.label }}</title>
          </path>
          <g v-for="table in layout.tables" :key="table.name" class="table" :transform="`translate(${table.x} ${table.y})`">
            <rect class="box" :width="BOX_WIDTH" :height="table.height" rx="6" />
            <rect class="header" :width="BOX_WIDTH" :height="HEADER_HEIGHT" rx="6" />
            <text class="name" x="12" :y="HEADER_HEIGHT / 2 + 5" role="link" tabindex="0" @click="open(table.name)" @keydown.enter="open(table.name)">{{ table.name }}</text>
            <g v-for="(column, index) in table.columns" :key="column.name" class="column" :transform="`translate(0 ${HEADER_HEIGHT + index * ROW_HEIGHT})`">
              <text v-if="column.primary" class="key" x="12" :y="ROW_HEIGHT / 2 + 4">⚷</text>
              <text class="col" x="28" :y="ROW_HEIGHT / 2 + 4">{{ column.name }}</text>
              <text class="type" :x="BOX_WIDTH - 12" :y="ROW_HEIGHT / 2 + 4" text-anchor="end">{{ column.type }}</text>
            </g>
          </g>
        </svg>
      </div>
    </template>
  </section>
</template>

<style scoped>
.pane { flex: 1; min-width: 0; overflow: auto; padding: 24px 28px 48px; display: flex; flex-direction: column; gap: 18px; }
h1 { font-size: 22px; font-weight: 700; }
.head { display: flex; flex-direction: column; gap: 4px; }
.meta { color: var(--mut); font-size: 13px; }
.canvas { overflow: auto; border: 1px solid var(--line); border-radius: 8px; background: var(--s1); }
svg { display: block; font: 400 12.5px var(--font-mono); }
.box { fill: var(--s2); stroke: var(--line-strong); }
.header { fill: var(--acc-bg); stroke: var(--acc-line); }
.name { fill: var(--fg); font: 600 13px var(--font-ui); cursor: pointer; }
.name:hover, .name:focus { fill: var(--acc); text-decoration: underline; outline: none; }
.col { fill: var(--fg); }
.type { fill: var(--mut); font-size: 11.5px; }
.key { fill: var(--sec); }
.relation { stroke: var(--acc); stroke-width: 1.5; opacity: 0.8; }
.relation:hover { stroke-width: 2.5; opacity: 1; }
.truncated { padding: 10px 12px; border-radius: var(--radius); background: var(--sec-bg); border: 1px solid var(--sec-line); color: var(--sec-ink); font-size: 13px; }
.error { padding: 10px 12px; border-radius: var(--radius); background: var(--bad-bg); border: 1px solid var(--bad-line); color: var(--bad); font-size: 13px; }
</style>
