<script setup lang="ts">
import { computed, ref } from 'vue'
import { messageFor } from '../api/errors'
import { fetchHealth, type HealthReport } from '../api/health'
import AppButton from '../components/AppButton.vue'
import { useWorkspaceStore } from '../stores/workspace'
import { describeStatus } from '../utils/diagnostics'
import { describeIndicator, formatIndicator } from '../utils/health'

const props = defineProps<{ id: string }>()
const workspace = useWorkspaceStore()

const INTERVALS = [1, 5, 10, 30]
const seconds = ref(5)
const report = ref<HealthReport | null>(null)
const failure = ref<string | null>(null)
const loading = ref(false)

const note = computed(() => (report.value === null ? null : describeStatus(report.value.status, 'la santé du serveur')))

async function measure(): Promise<void> {
  loading.value = true
  failure.value = null
  try {
    report.value = await fetchHealth(props.id, { database: workspace.database, schema: workspace.schema }, seconds.value)
  } catch (error) {
    report.value = null
    failure.value = messageFor(error)
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <section class="pane">
    <div class="head">
      <h1>Santé du serveur</h1>
      <p class="meta">Deux relevés de compteurs à quelques secondes d’écart : seul l’écart a un sens, un compteur seul est cumulé depuis le démarrage. Rien n’est conservé ni modifié. Les seuils sont des repères à valider sur votre charge, pas des verdicts.</p>
      <div class="tools">
        <label class="interval">Intervalle
          <select v-model.number="seconds" name="seconds" :disabled="loading">
            <option v-for="value in INTERVALS" :key="value" :value="value">{{ value }} s</option>
          </select>
        </label>
        <AppButton small :busy="loading" @click="measure">{{ loading ? `Mesure sur ${String(seconds)} s…` : 'Mesurer' }}</AppButton>
      </div>
    </div>

    <p v-if="failure" class="error" role="alert">{{ failure }}</p>
    <p v-else-if="note" class="note" role="status">{{ note }}</p>
    <p v-else-if="report?.discarded" class="note" role="status">Le serveur a redémarré (ou ses statistiques ont été remises à zéro) entre les deux relevés : la mesure est écartée, relancez-la.</p>
    <template v-else-if="report">
      <p class="meta">Mesuré sur {{ report.seconds }} s.</p>
      <ul class="cards">
        <li v-for="indicator in report.indicators" :key="indicator.key" data-testid="indicator" :data-level="indicator.level">
          <h2>{{ describeIndicator(indicator.key).label }}</h2>
          <p class="value">{{ formatIndicator(indicator) }}</p>
          <p v-if="indicator.level === 'warning'" class="flag">Au-delà du repère</p>
          <p class="hint">{{ describeIndicator(indicator.key).hint }}</p>
        </li>
      </ul>
    </template>
  </section>
</template>

<style scoped>
.pane { flex: 1; min-width: 0; overflow: auto; padding: 24px 28px 48px; display: flex; flex-direction: column; gap: 18px; }
h1 { font-size: 30px; line-height: 1.1; font-weight: 600; }
h2 { font-size: 14px; font-weight: 600; }
.head { display: flex; flex-direction: column; gap: 8px; }
.tools { display: flex; flex-wrap: wrap; gap: 16px; align-items: center; }
.interval { color: var(--mut); font-size: 13px; display: inline-flex; gap: 8px; align-items: center; }
.interval select { height: 32px; padding: 0 8px; border-radius: var(--radius); border: 1px solid var(--line); background: var(--s1); color: var(--fg); font: 400 13px var(--font-ui); }
.meta { color: var(--mut); font-size: 13px; }
.cards { list-style: none; margin: 0; padding: 0; display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 12px; }
.cards li { padding: 12px 14px; border-radius: var(--radius); border: 1px solid var(--line); background: var(--s1); display: flex; flex-direction: column; gap: 4px; }
.cards li[data-level='warning'] { border-color: var(--sec-line); background: var(--sec-bg); box-shadow: inset 3px 0 0 var(--sec); }
.value { font: 600 24px var(--font-mono); }
.flag { color: var(--sec-ink); font-size: 12px; font-weight: 500; }
.hint { color: var(--mut); font-size: 12.5px; }
.note { padding: 10px 12px; border-radius: var(--radius); background: var(--sec-bg); border: 1px solid var(--sec-line); color: var(--sec-ink); font-size: 13px; }
.error { padding: 10px 12px; border-radius: var(--radius); background: var(--bad-bg); border: 1px solid var(--bad-line); color: var(--bad); font-size: 13px; }
</style>
