<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { messageFor } from '../api/errors'
import { fetchTopQueries, type DiagnosticScope } from '../api/diagnostics'
import type { DigestOrder, DigestReport } from '../api/types'
import { describeStatus, enableHint, formatMs, formatRatio } from '../utils/diagnostics'
import AppButton from './AppButton.vue'

const props = defineProps<{ id: string; scope: DiagnosticScope }>()

const ORDERS: { value: DigestOrder; label: string }[] = [
  { value: 'total_time', label: 'Temps total' },
  { value: 'mean_time', label: 'Temps moyen' },
  { value: 'calls', label: 'Nombre d’appels' },
]
const LIMITS = [20, 50, 100]
// Au-delà de ce rapport, la requête lit sans doute bien plus de lignes qu'elle n'en rend : un index manque peut-être.
const SCAN_RATIO = 1000

const order = ref<DigestOrder>('total_time')
const limit = ref(20)
const report = ref<DigestReport | null>(null)
const error = ref<string | null>(null)
const loading = ref(false)
let sequence = 0

const note = computed(() => (report.value === null ? null : describeStatus(report.value.status, 'les requêtes coûteuses')))
const hint = computed(() => (report.value === null || note.value === null ? null : enableHint(report.value.status.reason)))
const readable = computed(() => report.value !== null && note.value === null)

async function load(): Promise<void> {
  const mine = ++sequence
  loading.value = true
  error.value = null
  try {
    const answer = await fetchTopQueries(props.id, props.scope, order.value, limit.value)
    // Une réponse périmée (autre classement demandé entre-temps) est ignorée.
    if (mine === sequence) report.value = answer
  } catch (failure: unknown) {
    if (mine === sequence) {
      report.value = null
      error.value = messageFor(failure)
    }
  } finally {
    if (mine === sequence) loading.value = false
  }
}

onMounted(load)
watch([order, limit], load)
</script>

<template>
  <section class="panel" aria-labelledby="top-queries-title">
    <h2 id="top-queries-title">Requêtes coûteuses par empreinte</h2>
    <p class="meta">
      Les requêtes que le serveur a vues, regroupées par empreinte : le texte est normalisé par le moteur, les valeurs y sont masquées (« ? »).
      Le bon classement est le temps total (fréquence × durée), et un rapport lignes examinées / lignes renvoyées très élevé trahit souvent un index manquant.
      Lecture seule : rien n’est remis à zéro depuis cette page ; les statistiques du moteur ne couvrent que sa fenêtre de collecte.
    </p>
    <div class="tools">
      <label>Classement
        <select v-model="order" name="order">
          <option v-for="choice in ORDERS" :key="choice.value" :value="choice.value">{{ choice.label }}</option>
        </select>
      </label>
      <label>Afficher
        <select v-model.number="limit" name="limit">
          <option v-for="choice in LIMITS" :key="choice" :value="choice">{{ choice }}</option>
        </select>
      </label>
      <AppButton small :busy="loading" @click="load">Actualiser</AppButton>
    </div>

    <p v-if="error" class="error" role="alert">{{ error }}</p>
    <p v-else-if="!report" class="meta" role="status">Lecture en cours…</p>
    <template v-else-if="note">
      <p class="note" role="status">{{ note }}</p>
      <p v-if="hint" class="hint" data-testid="hint">{{ hint }}</p>
    </template>
    <template v-else-if="readable && report">
      <p v-if="report.digests.length === 0" class="clean">Aucune requête enregistrée pour l’instant.</p>
      <div v-else class="scroll">
        <table data-testid="digests">
          <thead>
            <tr><th>Requête (empreinte)</th><th>Base</th><th>Appels</th><th>Temps total</th><th>Temps moyen</th><th>Maximum</th><th>Examinées</th><th>Renvoyées</th><th>Rapport</th><th>Sans index</th></tr>
          </thead>
          <tbody>
            <tr v-for="(digest, index) in report.digests" :key="index" :class="{ scan: digest.ratio !== null && digest.ratio >= SCAN_RATIO }">
              <td class="query">{{ digest.text ?? '' }}</td>
              <td>{{ digest.database ?? '' }}</td>
              <td>{{ digest.calls }}</td>
              <td>{{ formatMs(digest.totalMs) }}</td>
              <td>{{ formatMs(digest.meanMs) }}</td>
              <td>{{ digest.maxMs === null ? '' : formatMs(digest.maxMs) }}</td>
              <td>{{ digest.rowsExamined ?? '' }}</td>
              <td>{{ digest.rowsReturned ?? '' }}</td>
              <td>{{ formatRatio(digest.ratio) }}</td>
              <td>{{ digest.noIndexUsed ?? '' }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>
  </section>
</template>

<style scoped>
.panel { display: flex; flex-direction: column; gap: 12px; }
h2 { font-size: 15px; font-weight: 600; }
.meta { color: var(--mut); font-size: 13px; }
.tools { display: flex; flex-wrap: wrap; gap: 16px; align-items: center; }
.tools label { color: var(--mut); font-size: 13px; display: inline-flex; gap: 6px; align-items: center; }
.tools select { height: 32px; padding: 0 8px; border-radius: var(--radius); border: 1px solid var(--line); background: var(--s1); color: var(--fg); font: 400 13px var(--font-ui); }
.scroll { overflow-x: auto; }
table { border-collapse: collapse; width: 100%; font-size: 13px; }
th, td { text-align: left; padding: 6px 10px; border-bottom: 1px solid var(--line); vertical-align: top; }
th { color: var(--mut); font-weight: 500; white-space: nowrap; }
.query { font-family: var(--font-mono); font-size: 12px; max-width: 420px; overflow-wrap: anywhere; }
tr.scan { box-shadow: inset 3px 0 0 var(--sec); background: var(--sec-bg); }
.hint { padding: 8px 12px; border-radius: var(--radius); background: var(--bg); border: 1px solid var(--line); font: 400 12.5px/1.5 var(--font-mono); overflow-wrap: anywhere; }
.clean { padding: 12px 14px; border-radius: var(--radius); background: var(--ok-bg); border: 1px solid var(--ok-line); color: var(--ok); }
.note { padding: 10px 12px; border-radius: var(--radius); background: var(--sec-bg); border: 1px solid var(--sec-line); color: var(--sec-ink); font-size: 13px; }
.error { padding: 10px 12px; border-radius: var(--radius); background: var(--bad-bg); border: 1px solid var(--bad-line); color: var(--bad); font-size: 13px; }
</style>
