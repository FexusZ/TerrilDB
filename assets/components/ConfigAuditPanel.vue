<script setup lang="ts">
import { computed, ref } from 'vue'
import { fetchConfigAudit } from '../api/diagnostics'
import { messageFor } from '../api/errors'
import type { ConfigAuditReport } from '../api/types'
import { describeStatus } from '../utils/diagnostics'
import AppButton from './AppButton.vue'

const props = defineProps<{ id: string; database: string | null; schema: string | null }>()

const report = ref<ConfigAuditReport | null>(null)
const error = ref<string | null>(null)
const loading = ref(false)

const LABELS = { high: 'Grave', medium: 'Moyen', low: 'Faible' } as const

const note = computed(() => {
  if (report.value === null) return null
  if (report.value.status.availability === 'unsupported' && report.value.status.reason === 'no_rules') return 'Aucune règle d’audit pour ce moteur.'

  return describeStatus(report.value.status, 'la configuration du serveur')
})

async function load(): Promise<void> {
  loading.value = true
  error.value = null
  try {
    report.value = await fetchConfigAudit(props.id, { database: props.database, schema: props.schema })
  } catch (failure) {
    report.value = null
    error.value = messageFor(failure)
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <section class="audit" aria-labelledby="config-audit-title">
    <div class="head">
      <h2 id="config-audit-title">Audit de la configuration</h2>
      <AppButton small :busy="loading" @click="load">{{ report === null ? 'Lancer l’audit' : 'Relancer' }}</AppButton>
    </div>
    <p class="meta">Des règles sur les variables lues, chacune justifiée par la documentation du moteur : rien n’est exécuté ni modifié. Les commandes proposées sont à relire et à jouer vous-même ; une règle dont la variable est masquée ou illisible se tait.</p>

    <p v-if="error" class="error" role="alert">{{ error }}</p>
    <p v-else-if="note" class="note" role="status">{{ note }}</p>
    <template v-else-if="report">
      <p class="meta" role="status">{{ report.evaluated }} règles sur {{ report.rulesTotal }} ont pu être jugées.<template v-if="report.evaluated < report.rulesTotal"> Les autres n’ont pas leurs variables : « aucune constatation » ne les couvre pas.</template></p>
      <p v-if="report.findings.length === 0" class="clean">Aucune constatation.</p>
      <ul v-else class="findings" data-testid="config-findings">
        <li v-for="finding in report.findings" :key="`${finding.rule}/${finding.variable}`" :class="finding.severity">
          <div class="line">
            <span class="sev">{{ LABELS[finding.severity] }}</span>
            <strong class="var">{{ finding.variable }}</strong>
            <span class="vals">valeur actuelle <code>{{ finding.value === '' ? '(vide)' : finding.value }}</code>, recommandée : {{ finding.recommended }}</span>
          </div>
          <p class="why">{{ finding.explanation }}</p>
          <code v-if="finding.suggestion !== null" class="cmd">{{ finding.suggestion }}</code>
        </li>
      </ul>
    </template>
  </section>
</template>

<style scoped>
.audit { display: flex; flex-direction: column; gap: 10px; }
.head { display: flex; gap: 16px; align-items: center; }
h2 { font-size: 20px; font-weight: 600; }
.meta { color: var(--mut); font-size: 13px; max-width: 78ch; }
.clean { color: var(--ok); font-size: 13px; }
.findings { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 6px; }
.findings li { display: flex; flex-direction: column; gap: 4px; padding: 8px 12px; border-radius: var(--radius); border: 1px solid var(--line); background: var(--s1); font-size: 13px; }
.findings li.high { border-color: var(--bad-line); background: var(--bad-bg); }
.findings li.medium { border-color: var(--sec-line); background: var(--sec-bg); }
.line { display: flex; flex-wrap: wrap; gap: 8px; align-items: baseline; }
.sev { font-weight: 600; }
.var { font-family: var(--font-mono); font-size: 12.5px; }
.vals { color: var(--mut); }
.why { margin: 0; max-width: 90ch; }
code { font-family: var(--font-mono); font-size: 12px; }
.cmd { display: block; padding: 4px 8px; border-radius: var(--radius); background: var(--s2); overflow-wrap: anywhere; user-select: all; }
.note { padding: 10px 12px; border-radius: var(--radius); background: var(--sec-bg); border: 1px solid var(--sec-line); color: var(--sec-ink); font-size: 13px; }
.error { padding: 10px 12px; border-radius: var(--radius); background: var(--bad-bg); border: 1px solid var(--bad-line); color: var(--bad); font-size: 13px; }
</style>
