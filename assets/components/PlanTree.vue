<script setup lang="ts">
import type { PlanResult } from '../api/types'
import { describePlanFinding } from '../utils/optimizer'
import PlanNodeView from './PlanNodeView.vue'

defineProps<{ result: PlanResult }>()
const cost = new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 2 })
</script>

<template>
  <section class="plan" aria-label="Plan d’exécution">
    <p class="summary">
      <template v-if="result.plan.totalCost !== null">Coût estimé : {{ cost.format(result.plan.totalCost) }} · </template>
      Plan estimé par le moteur : la requête n’a pas été exécutée, les nombres sont des estimations.
    </p>

    <ul v-if="result.findings.length > 0" class="findings">
      <li v-for="(finding, index) in result.findings" :key="index" class="finding">{{ describePlanFinding(finding) }}</li>
    </ul>
    <p v-else class="fine">Rien d’inquiétant dans ce plan.</p>

    <ul class="tree"><PlanNodeView :node="result.plan.root" /></ul>
  </section>
</template>

<style scoped>
.plan { display: flex; flex-direction: column; gap: 14px; padding: 16px; }
.summary { color: var(--mut); font-size: 13px; }
.findings { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 8px; }
.finding { padding: 10px 12px; border-radius: var(--radius); background: var(--sec-bg); border: 1px solid var(--sec-line); color: var(--sec-ink); font-size: 13px; }
.fine { padding: 10px 12px; border-radius: var(--radius); background: var(--ok-bg); border: 1px solid var(--ok-line); color: var(--ok); font-size: 13px; }
.tree { list-style: none; margin: 0; padding: 0; }
</style>
