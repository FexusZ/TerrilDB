<script setup lang="ts">
import { computed, onMounted, ref, useTemplateRef } from 'vue'
import { useRouter } from 'vue-router'
import { api } from '../api/client'
import { messageFor } from '../api/errors'
import type { SchemaDiagram } from '../api/types'
import { HEADER_HEIGHT, ROW_HEIGHT, layoutDiagram } from '../utils/erLayout'

import { useWorkspaceStore } from '../stores/workspace'

const props = defineProps<{ id: string }>()
const workspace = useWorkspaceStore()
const router = useRouter()

const diagram = ref<SchemaDiagram | null>(null)

// Paliers de zoom (en %) ; « Ajuster » peut tomber entre deux paliers, le bouton suivant reprend alors au palier voisin.
const STEPS = [25, 33, 50, 67, 80, 100, 125, 150, 200, 250, 300]
const MIN_ZOOM = STEPS[0] ?? 25
const MAX_ZOOM = STEPS[STEPS.length - 1] ?? 300
const zoom = ref(100)
const canvas = useTemplateRef<HTMLElement>('canvas')
const canZoomIn = computed(() => zoom.value < MAX_ZOOM)
const canZoomOut = computed(() => zoom.value > MIN_ZOOM)

function zoomIn(): void {
  zoom.value = STEPS.find((step) => step > zoom.value) ?? MAX_ZOOM
}

function zoomOut(): void {
  zoom.value = [...STEPS].reverse().find((step) => step < zoom.value) ?? MIN_ZOOM
}

function fit(): void {
  const available = canvas.value?.clientWidth ?? 0
  if (layout.value === null || available <= 0) {
    return
  }
  // Jamais agrandi au-delà de la taille réelle : ajuster sert à faire tenir, pas à grossir.
  zoom.value = Math.min(100, Math.max(MIN_ZOOM, (available / layout.value.width) * 100))
}

// Ctrl + molette zoome ; la molette seule continue de faire défiler.
function onWheel(event: WheelEvent): void {
  if (!event.ctrlKey) {
    return
  }
  event.preventDefault()
  if (event.deltaY < 0) {
    zoomIn()
  } else if (event.deltaY > 0) {
    zoomOut()
  }
}
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
    diagram.value = await api<SchemaDiagram>('GET', `/web/connections/${props.id}/diagram${workspace.scopeSuffix()}`)
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
      <div v-else class="zoom" role="group" aria-label="Zoom">
        <button type="button" aria-label="Zoom arrière" :disabled="!canZoomOut" @click="zoomOut">−</button>
        <span data-testid="zoom-level" class="level" aria-live="polite">{{ Math.round(zoom) }} %</span>
        <button type="button" aria-label="Zoom avant" :disabled="!canZoomIn" @click="zoomIn">+</button>
        <button type="button" aria-label="Taille réelle" :disabled="zoom === 100" @click="zoom = 100">100 %</button>
        <button type="button" aria-label="Ajuster à la largeur" @click="fit">Ajuster</button>
      </div>
      <div v-if="diagram.tables.length > 0" ref="canvas" class="canvas" @wheel="onWheel">
        <svg :width="Math.floor(layout.width * zoom / 100)" :height="Math.floor(layout.height * zoom / 100)" :viewBox="`0 0 ${layout.width} ${layout.height}`" role="img" aria-label="Diagramme entité-relation du schéma">
          <path v-for="edge in relations" :key="edge.name + edge.from" class="relation" :d="edge.path" fill="none">
            <title>{{ edge.label }}</title>
          </path>
          <g v-for="table in layout.tables" :key="table.name" class="table" :transform="`translate(${table.x} ${table.y})`">
            <rect class="box" :width="table.width" :height="table.height" rx="6" />
            <rect class="header" :width="table.width" :height="HEADER_HEIGHT" rx="6" />
            <text class="name" x="12" :y="HEADER_HEIGHT / 2 + 5" role="link" tabindex="0" @click="open(table.name)" @keydown.enter="open(table.name)">{{ table.name }}</text>
            <g v-for="(column, index) in table.columns" :key="column.name" class="column" :transform="`translate(0 ${HEADER_HEIGHT + index * ROW_HEIGHT})`">
              <text v-if="column.primary" class="key" x="12" :y="ROW_HEIGHT / 2 + 4">⚷</text>
              <text class="col" x="28" :y="ROW_HEIGHT / 2 + 4">{{ column.name }}</text>
              <text class="type" :x="table.width - 12" :y="ROW_HEIGHT / 2 + 4" text-anchor="end">{{ column.type }}</text>
            </g>
          </g>
        </svg>
      </div>
    </template>
  </section>
</template>

<style scoped>
.pane { flex: 1; min-width: 0; overflow: auto; padding: 24px 28px 48px; display: flex; flex-direction: column; gap: 18px; }
h1 { font-size: 30px; line-height: 1.1; font-weight: 600; }
.head { display: flex; flex-direction: column; gap: 4px; }
.meta { color: var(--mut); font-size: 13px; }
.zoom { display: flex; align-items: center; gap: 6px; }
.zoom button { height: 30px; min-width: 30px; padding: 0 10px; border-radius: var(--radius); border: 1px solid var(--line); background: var(--s1); color: var(--fg); font: 500 13px var(--font-ui); cursor: pointer; }
.zoom button:hover:not(:disabled) { border-color: var(--acc-line); color: var(--acc); }
.zoom button:disabled { opacity: 0.45; cursor: default; }
.level { min-width: 52px; text-align: center; color: var(--mut); font: 400 12px var(--font-mono); }
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
