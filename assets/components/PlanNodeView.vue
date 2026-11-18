<script setup lang="ts">
import { computed } from 'vue'
import type { PlanNodeInfo } from '../api/types'
import { formatCount, formatEstimate, formatMilliseconds } from '../utils/format'
import { planKindLabel } from '../utils/optimizer'

const props = defineProps<{ node: PlanNodeInfo; measured?: boolean }>()
const cost = new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 2 })

const actualRows = computed(() => (props.measured ? (props.node.actualRows ?? null) : null))
/** Même règle que le serveur : un accès à une table dont l'estimation s'écarte du réel d'un facteur 100 au moins, pour 1 000 lignes réelles au moins. */
const off = computed(() => {
  const estimated = props.node.rows
  const actual = actualRows.value
  if (props.node.relation === null || estimated === null || actual === null || actual < 1000) {
    return false
  }

  return Math.max(estimated, actual) >= 100 * Math.max(1, Math.min(estimated, actual))
})
const buffers = computed(() => (props.measured ? (props.node.buffers ?? null) : null))
</script>

<template>
  <li class="node" :class="{ scan: node.fullScan, off }">
    <div class="row">
      <span class="kind">{{ planKindLabel(node.kind, node.label) }}</span>
      <span v-if="node.relation" class="relation mono">{{ node.relation }}</span>
      <span v-if="node.index" class="meta">via <span class="mono">{{ node.index }}</span></span>
      <template v-if="actualRows !== null">
        <span class="meta actual">réel {{ formatCount(actualRows) }} {{ actualRows === 1 ? 'ligne' : 'lignes' }}</span>
        <span v-if="node.rows !== null" class="meta">estimé {{ formatEstimate(node.rows) }}</span>
      </template>
      <span v-else-if="node.rows !== null" class="meta">{{ formatEstimate(node.rows) }} lignes</span>
      <span v-if="measured && node.loops === 0" class="meta">jamais exécuté</span>
      <span v-else-if="measured && node.loops !== undefined && node.loops !== null && node.loops > 1" class="meta">× {{ formatCount(node.loops) }}</span>
      <span v-if="measured && node.actualTimeMs !== undefined && node.actualTimeMs !== null" class="meta">{{ formatMilliseconds(node.actualTimeMs) }}</span>
      <span v-if="measured && node.sortsOnDisk" class="meta disk">tri sur disque</span>
      <span v-if="node.cost !== null" class="meta">coût {{ cost.format(node.cost) }}</span>
    </div>
    <p v-if="buffers" class="meta buffers">
      {{ formatCount(buffers.sharedHit) }} pages en cache, {{ formatCount(buffers.sharedRead) }} lues sur disque<template v-if="buffers.tempWritten > 0">, {{ formatCount(buffers.tempWritten) }} écrites en fichiers temporaires</template>
    </p>
    <p v-if="node.condition" class="condition mono">{{ node.condition }}</p>
    <ul v-if="node.children.length > 0" class="children">
      <PlanNodeView v-for="(child, index) in node.children" :key="index" :node="child" :measured="measured" />
    </ul>
  </li>
</template>

<style scoped>
.node { padding: 4px 0; }
.row { display: flex; flex-wrap: wrap; gap: 8px 12px; align-items: baseline; }
.kind { font-weight: 600; }
.scan > .row .kind { color: var(--sec); }
.off > .row .actual { color: var(--sec); font-weight: 600; }
.disk { color: var(--sec); font-weight: 600; }
.relation { color: var(--acc); }
.meta { color: var(--mut); font-size: 12px; }
.buffers { margin: 2px 0 0; }
.mono { font-family: var(--font-mono); font-size: 12.5px; }
.condition { margin: 2px 0 0; color: var(--mut); font-size: 12px; overflow-wrap: anywhere; }
.children { list-style: none; margin: 4px 0 0 6px; padding: 0 0 0 16px; border-left: 1px solid var(--line); }
</style>
