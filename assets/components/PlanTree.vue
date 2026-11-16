<script setup lang="ts">
import { computed } from 'vue'
import type { PlanResult } from '../api/types'
import { formatMilliseconds } from '../utils/format'
import { describePlanFinding, planFindingHelp } from '../utils/optimizer'
import PlanNodeView from './PlanNodeView.vue'

const props = defineProps<{ result: PlanResult }>()
const cost = new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 2 })
const measured = computed(() => props.result.plan.measured === true)
</script>

<template>
  <section class="plan" aria-label="Plan d’exécution">
    <p v-if="measured" class="summary">
      Plan mesuré : la requête a été réellement exécutée<template v-if="result.plan.executionTimeMs !== null && result.plan.executionTimeMs !== undefined"> en {{ formatMilliseconds(result.plan.executionTimeMs) }}</template>.
      « Réel » est ce que cette exécution a produit ; « estimé » reste l’avis du moteur avant d’exécuter.
    </p>
    <p v-else class="summary">
      <template v-if="result.plan.totalCost !== null">Coût estimé : {{ cost.format(result.plan.totalCost) }} · </template>
      Plan estimé par le moteur : la requête n’a pas été exécutée, les nombres sont des estimations.
    </p>
    <p v-if="result.measure?.status === 'unsupported'" class="measure-note" role="status">Ce moteur ne sait pas mesurer un plan : voici son plan estimé.</p>

    <ul v-if="result.findings.length > 0" class="findings">
      <li v-for="(finding, index) in result.findings" :key="index" class="finding">
        <span>{{ describePlanFinding(finding) }}</span>
        <template v-if="planFindingHelp(finding)">
          <span class="help">{{ planFindingHelp(finding) }}</span>
        </template>
        <code v-if="finding.suggestion" class="suggestion">{{ finding.suggestion }}</code>
      </li>
    </ul>
    <p v-else class="fine">Rien d’inquiétant dans ce plan.</p>

    <ul class="tree"><PlanNodeView :node="result.plan.root" :measured="measured" /></ul>
  </section>
</template>

<style scoped>
.plan { display: flex; flex-direction: column; gap: 14px; padding: 16px; }
.summary { color: var(--mut); font-size: 13px; }
.measure-note { color: var(--mut); font-size: 13px; }
.findings { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 8px; }
.finding { display: flex; flex-direction: column; gap: 4px; padding: 10px 12px; border-radius: var(--radius); background: var(--sec-bg); border: 1px solid var(--sec-line); color: var(--sec-ink); font-size: 13px; }
.help { color: var(--mut); font-size: 12.5px; }
.suggestion { font-family: var(--font-mono); font-size: 12.5px; overflow-wrap: anywhere; user-select: all; }
.fine { padding: 10px 12px; border-radius: var(--radius); background: var(--ok-bg); border: 1px solid var(--ok-line); color: var(--ok); font-size: 13px; }
.tree { list-style: none; margin: 0; padding: 0; }
</style>
