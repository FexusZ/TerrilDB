<script setup lang="ts">
import type { PlanNodeInfo } from '../api/types'
import { formatEstimate } from '../utils/format'
import { planKindLabel } from '../utils/optimizer'

defineProps<{ node: PlanNodeInfo }>()
const cost = new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 2 })
</script>

<template>
  <li class="node" :class="{ scan: node.fullScan }">
    <div class="row">
      <span class="kind">{{ planKindLabel(node.kind, node.label) }}</span>
      <span v-if="node.relation" class="relation mono">{{ node.relation }}</span>
      <span v-if="node.index" class="meta">via <span class="mono">{{ node.index }}</span></span>
      <span v-if="node.rows !== null" class="meta">{{ formatEstimate(node.rows) }} lignes</span>
      <span v-if="node.cost !== null" class="meta">coût {{ cost.format(node.cost) }}</span>
    </div>
    <p v-if="node.condition" class="condition mono">{{ node.condition }}</p>
    <ul v-if="node.children.length > 0" class="children">
      <PlanNodeView v-for="(child, index) in node.children" :key="index" :node="child" />
    </ul>
  </li>
</template>

<style scoped>
.node { padding: 4px 0; }
.row { display: flex; flex-wrap: wrap; gap: 8px 12px; align-items: baseline; }
.kind { font-weight: 600; }
.scan > .row .kind { color: var(--sec); }
.relation { color: var(--acc); }
.meta { color: var(--mut); font-size: 12px; }
.mono { font-family: var(--font-mono); font-size: 12.5px; }
.condition { margin: 2px 0 0; color: var(--mut); font-size: 12px; overflow-wrap: anywhere; }
.children { list-style: none; margin: 4px 0 0 6px; padding: 0 0 0 16px; border-left: 1px solid var(--line); }
</style>
