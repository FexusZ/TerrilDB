<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { messageFor } from '../api/errors'
import { fetchReplication } from '../api/replication'
import type { ReplicationReport } from '../api/types'
import AppButton from '../components/AppButton.vue'
import { useWorkspaceStore } from '../stores/workspace'
import { describeStatus, formatSeconds } from '../utils/diagnostics'
import { alertText, lagNote, roleLabel, threadLabel } from '../utils/replication'

const props = defineProps<{ id: string }>()
const workspace = useWorkspaceStore()

const report = ref<ReplicationReport | null>(null)
const failure = ref<string | null>(null)
const loading = ref(false)

const note = computed(() => (report.value === null ? null : describeStatus(report.value.status, 'la réplication')))
const readable = computed(() => report.value !== null && note.value === null)

async function load(): Promise<void> {
  loading.value = true
  failure.value = null
  try {
    report.value = await fetchReplication(props.id, { database: workspace.database, schema: workspace.schema })
  } catch (error) {
    report.value = null
    failure.value = messageFor(error)
  } finally {
    loading.value = false
  }
}

onMounted(load)

const flag = (value: boolean | null): string => (value === null ? 'inconnu' : value ? 'oui' : 'non')
</script>

<template>
  <section class="pane">
    <div class="head">
      <h1>Réplication</h1>
      <p class="meta">Lecture seule, à la demande. Rien n’est exécuté depuis cette page : aucun démarrage, arrêt ni changement de réplication, et on ne « saute » aucune transaction.</p>
      <div class="tools"><AppButton small :busy="loading" @click="load">Actualiser</AppButton></div>
    </div>

    <p v-if="failure" class="error" role="alert">{{ failure }}</p>
    <p v-else-if="!report" class="meta" role="status">Lecture en cours…</p>
    <p v-else-if="note" class="note" role="status">{{ note }}</p>

    <template v-if="report && readable">
      <section class="group" aria-labelledby="role-title">
        <h2 id="role-title">{{ roleLabel(report.role) }}</h2>
        <p v-if="report.role === 'none'" class="clean">Ce serveur ne réplique pas : il n’est ni réplica ni source de réplicas connectés.</p>
        <p v-if="report.role !== 'replica'" class="meta">Réplicas connectés : {{ report.replicaCount ?? 'inconnu (le compte n’a pas le droit de les lister)' }}</p>
        <p v-else-if="report.replicaCount" class="meta">Réplicas connectés à ce réplica : {{ report.replicaCount }}</p>
      </section>

      <section v-if="report.alerts.length > 0" class="group" aria-labelledby="alerts-title">
        <h2 id="alerts-title">Alertes</h2>
        <ul class="alerts" data-testid="alerts">
          <li v-for="(alert, index) in report.alerts" :key="index" :class="alert.severity">{{ alertText(alert, report.thresholds) }}</li>
        </ul>
      </section>

      <section v-for="channel in report.channels" :key="channel.name" class="group" :aria-label="channel.name === '' ? 'Réplication entrante' : `Canal ${channel.name}`">
        <h2>{{ channel.name === '' ? 'Réplication entrante' : `Canal ${channel.name}` }}</h2>
        <dl class="facts">
          <div><dt>Fil de réception</dt><dd>{{ threadLabel(channel.receiver) }}</dd></div>
          <div><dt>Fil d’application</dt><dd>{{ threadLabel(channel.applier) }}</dd></div>
          <div><dt>Retard annoncé</dt><dd>{{ channel.lagSeconds === null ? 'inconnu' : formatSeconds(channel.lagSeconds) }}</dd></div>
          <div><dt>Reçu</dt><dd><code>{{ channel.received ?? '—' }}</code></dd></div>
          <div><dt>Appliqué</dt><dd><code>{{ channel.applied ?? '—' }}</code></dd></div>
          <div><dt>Reçu et pas encore appliqué</dt><dd>{{ flag(channel.pendingApply) }}</dd></div>
        </dl>
        <p v-if="lagNote(channel)" class="note" role="status">{{ lagNote(channel) }}</p>
        <div v-if="channel.receiverError" class="failure">
          <h3>Dernière erreur du fil de réception<template v-if="channel.receiverError.code !== null"> · {{ channel.receiverError.code }}</template></h3>
          <pre>{{ channel.receiverError.message }}</pre>
        </div>
        <div v-if="channel.applierError" class="failure">
          <h3>Dernière erreur du fil d’application<template v-if="channel.applierError.code !== null"> · {{ channel.applierError.code }}</template></h3>
          <pre>{{ channel.applierError.message }}</pre>
          <p class="meta">Le texte est neutralisé (hôtes, comptes et adresses remplacés par « … »). Comprenez la cause avant d’envisager de sauter une transaction : TerrilDB ne le fait pas.</p>
        </div>
      </section>

      <section v-if="report.role !== 'none'" class="group" aria-labelledby="settings-title">
        <h2 id="settings-title">Réglages du serveur</h2>
        <dl class="facts">
          <div><dt>Lecture seule</dt><dd>{{ flag(report.settings.superReadOnly ?? report.settings.readOnly) }}</dd></div>
          <div><dt>Format du journal binaire</dt><dd>{{ report.settings.binlogFormat ?? 'inconnu' }}</dd></div>
          <div><dt>GTID</dt><dd>{{ flag(report.settings.gtid) }}</dd></div>
        </dl>
      </section>
    </template>
  </section>
</template>

<style scoped>
.pane { flex: 1; min-width: 0; overflow: auto; padding: 24px 28px 48px; display: flex; flex-direction: column; gap: 22px; }
h1 { font-size: 30px; line-height: 1.1; font-weight: 600; }
h2 { font-size: 15px; font-weight: 600; margin-bottom: 8px; }
h3 { font-size: 13px; font-weight: 600; margin: 10px 0 4px; }
.head { display: flex; flex-direction: column; gap: 8px; }
.tools { display: flex; gap: 16px; align-items: center; }
.meta { color: var(--mut); font-size: 13px; }
.facts { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 10px 20px; margin: 0; }
.facts dt { color: var(--mut); font-size: 12.5px; }
.facts dd { margin: 2px 0 0; overflow-wrap: anywhere; }
code { font: 400 12.5px var(--font-mono); }
pre { margin: 4px 0 0; padding: 6px 10px; border-radius: var(--radius); background: var(--bg); border: 1px solid var(--line); font: 400 12.5px/1.5 var(--font-mono); white-space: pre-wrap; overflow-wrap: anywhere; }
.alerts { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 6px; }
.alerts li { padding: 10px 12px; border-radius: var(--radius); background: var(--sec-bg); border: 1px solid var(--sec-line); color: var(--sec-ink); font-size: 13px; }
.alerts li.critical { background: var(--bad-bg); border-color: var(--bad-line); color: var(--bad); }
.clean { padding: 12px 14px; border-radius: var(--radius); background: var(--ok-bg); border: 1px solid var(--ok-line); color: var(--ok); }
.note { padding: 10px 12px; border-radius: var(--radius); background: var(--sec-bg); border: 1px solid var(--sec-line); color: var(--sec-ink); font-size: 13px; }
.error { padding: 10px 12px; border-radius: var(--radius); background: var(--bad-bg); border: 1px solid var(--bad-line); color: var(--bad); font-size: 13px; }
</style>
