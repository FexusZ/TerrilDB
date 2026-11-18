<script setup lang="ts">
import type { LintResult } from '../api/types'
import { lintRuleLabel } from '../utils/optimizer'

defineProps<{ result: LintResult }>()
</script>

<template>
  <section class="lint" aria-label="Conseils sur la requête">
    <p class="summary">Conseils tirés du texte de la requête : rien n’a été exécuté, et aucun ne vous empêche de l’exécuter. Le plan d’exécution dit ce que le moteur fera réellement.</p>

    <ul v-if="result.findings.length > 0" class="findings">
      <li v-for="(finding, index) in result.findings" :key="index" class="finding" :class="finding.severity">
        <p class="rule">
          <span class="label">{{ lintRuleLabel(finding.rule) }}</span>
          <code v-for="column in finding.columns" :key="column" class="column">{{ column }}</code>
        </p>
        <p class="message">{{ finding.message }}</p>
        <p v-if="finding.suggestion" class="suggestion">{{ finding.suggestion }}</p>
      </li>
    </ul>
    <p v-else class="fine">Aucun motif connu repéré dans ce texte.</p>
  </section>
</template>

<style scoped>
.lint { display: flex; flex-direction: column; gap: 14px; padding: 16px; }
.summary { color: var(--mut); font-size: 13px; }
.findings { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 8px; }
.finding { padding: 10px 12px; border-radius: var(--radius); background: var(--sec-bg); border: 1px solid var(--sec-line); color: var(--sec-ink); font-size: 13px; display: flex; flex-direction: column; gap: 4px; }
.suggestion { color: var(--mut); }
.rule { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; font-size: 12px; }
.label { font-weight: 600; }
.column { font-family: var(--font-mono); font-size: 12px; padding: 0 6px; border-radius: var(--radius); background: var(--sec-line); }
.fine { padding: 10px 12px; border-radius: var(--radius); background: var(--ok-bg); border: 1px solid var(--ok-line); color: var(--ok); font-size: 13px; }
</style>
