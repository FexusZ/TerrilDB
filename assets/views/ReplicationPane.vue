<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { messageFor } from '../api/errors'
import { fetchReplication } from '../api/replication'
import type { ReplicationReport } from '../api/types'
import AppButton from '../components/AppButton.vue'
import { useWorkspaceStore } from '../stores/workspace'
import { describeStatus, formatSeconds } from '../utils/diagnostics'
import { alertText, formatBytes, lagNote, roleLabel, sectionNote, semiSyncLabel, threadLabel, walStatusLabel } from '../utils/replication'

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
const linkLabel = (status: string | null): string => (status === 'up' ? 'établi' : status === 'down' ? 'coupé' : 'inconnu')
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
        <p v-if="report.role === 'source' || report.role === 'none'" class="meta">Réplicas connectés : {{ report.replicaCount ?? 'inconnu (le compte n’a pas le droit de les lister)' }}</p>
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

      <section v-if="report.slots" class="group" aria-labelledby="slots-title">
        <h2 id="slots-title">Slots de réplication</h2>
        <p v-if="sectionNote(report.slots.status, 'les slots de réplication')" class="note" role="status">{{ sectionNote(report.slots.status, 'les slots de réplication') }}</p>
        <p v-else-if="report.slots.slots.length === 0" class="meta">Aucun slot de réplication.</p>
        <template v-else>
          <p class="meta">Un slot que personne ne lit retient du WAL : au-delà de {{ formatBytes(report.limits?.slotRetainedWarningBytes ?? null) }} l’alerte s’allume, à {{ formatBytes(report.limits?.slotRetainedCriticalBytes ?? null) }} elle devient critique.</p>
          <table class="grid" data-testid="slots">
            <thead><tr><th>Slot</th><th>Type</th><th>État</th><th>WAL retenu</th><th>État du WAL</th><th>Marge avant perte</th></tr></thead>
            <tbody>
              <tr v-for="slot in report.slots.slots" :key="slot.name" :class="{ idle: !slot.active && !slot.temporary }">
                <td><code>{{ slot.name }}</code></td>
                <td>{{ slot.type === 'logical' ? 'logique' : 'physique' }}{{ slot.temporary ? ', temporaire' : '' }}</td>
                <td>{{ slot.active ? 'actif' : 'inactif' }}</td>
                <td>{{ formatBytes(slot.retainedBytes) }}</td>
                <td>{{ walStatusLabel(slot.walStatus) }}</td>
                <td>{{ slot.safeWalSize === null ? 'sans plafond' : formatBytes(slot.safeWalSize) }}</td>
              </tr>
            </tbody>
          </table>
        </template>
      </section>

      <section v-if="report.replicas" class="group" aria-labelledby="links-title">
        <h2 id="links-title">Réplicas connectés à cette source</h2>
        <p v-if="sectionNote(report.replicas.status, 'les réplicas connectés')" class="note" role="status">{{ sectionNote(report.replicas.status, 'les réplicas connectés') }}</p>
        <p v-else-if="report.replicas.replicas.length === 0" class="meta">Aucun réplica connecté : une liste vide est normale sur un serveur qui n’a pas de réplica.</p>
        <table v-else class="grid" data-testid="replicas">
          <thead><tr><th>Réplica</th><th>État</th><th>Mode</th><th>Retard d’écriture</th><th>Retard de vidage</th><th>Retard de rejeu</th><th>Décalage</th></tr></thead>
          <tbody>
            <tr v-for="link in report.replicas.replicas" :key="link.rank">
              <td>n° {{ link.rank }}</td>
              <td>{{ link.state ?? 'inconnu' }}</td>
              <td>{{ link.syncState ?? '—' }}</td>
              <td>{{ link.writeLagSeconds === null ? '—' : formatSeconds(link.writeLagSeconds) }}</td>
              <td>{{ link.flushLagSeconds === null ? '—' : formatSeconds(link.flushLagSeconds) }}</td>
              <td>{{ link.replayLagSeconds === null ? '—' : formatSeconds(link.replayLagSeconds) }}</td>
              <td>{{ formatBytes(link.behindBytes) }}</td>
            </tr>
          </tbody>
        </table>
      </section>

      <section v-if="report.binlog" class="group" aria-labelledby="binlog-title">
        <h2 id="binlog-title">Journaux binaires</h2>
        <p v-if="sectionNote(report.binlog.status, 'les journaux binaires')" class="note" role="status">{{ sectionNote(report.binlog.status, 'les journaux binaires') }}</p>
        <dl v-else class="facts">
          <div><dt>Fichiers</dt><dd>{{ report.binlog.fileCount ?? 'inconnu' }}</dd></div>
          <div><dt>Taille totale</dt><dd>{{ formatBytes(report.binlog.totalBytes) }}</dd></div>
          <div><dt>Expiration</dt><dd>{{ report.binlog.expireSeconds === null ? 'inconnue' : report.binlog.expireSeconds === 0 ? 'jamais' : formatSeconds(report.binlog.expireSeconds) }}</dd></div>
        </dl>
        <dl v-if="report.binlog.gtidExecuted || report.binlog.gtidPurged" class="facts">
          <div v-if="report.binlog.gtidExecuted"><dt>GTID exécutés</dt><dd><code>{{ report.binlog.gtidExecuted }}</code></dd></div>
          <div v-if="report.binlog.gtidPurged"><dt>GTID purgés des journaux</dt><dd><code>{{ report.binlog.gtidPurged }}</code></dd></div>
        </dl>
      </section>

      <section v-if="report.semiSync" class="group" aria-labelledby="semi-title">
        <h2 id="semi-title">Semi-synchrone</h2>
        <p v-if="sectionNote(report.semiSync.status, 'l’état semi-synchrone')" class="note" role="status">{{ sectionNote(report.semiSync.status, 'l’état semi-synchrone') }}</p>
        <dl class="facts">
          <div><dt>Côté source</dt><dd>{{ semiSyncLabel(report.semiSync.source) }}</dd></div>
          <div><dt>Côté réplica</dt><dd>{{ semiSyncLabel(report.semiSync.replica) }}</dd></div>
          <div v-if="report.semiSync.clients !== null"><dt>Réplicas semi-synchrones</dt><dd>{{ report.semiSync.clients }}</dd></div>
        </dl>
      </section>

      <section v-if="report.replicatedTables" class="group" aria-labelledby="tables-title">
        <h2 id="tables-title">Tables répliquées entre pairs</h2>
        <p v-if="sectionNote(report.replicatedTables.status, 'les tables répliquées')" class="note" role="status">{{ sectionNote(report.replicatedTables.status, 'les tables répliquées') }}</p>
        <p v-else-if="report.replicatedTables.tables.length === 0" class="meta">Aucune table répliquée sur ce serveur.</p>
        <table v-else class="grid" data-testid="replicated-tables">
          <thead><tr><th>Table</th><th>Lecture seule</th><th>Session</th><th>Retard</th><th>File d’attente</th><th>Insertions en attente</th><th>Parts perdues</th></tr></thead>
          <tbody>
            <tr v-for="table in report.replicatedTables.tables" :key="`${table.database}.${table.table}`">
              <td><code>{{ table.database }}.{{ table.table }}</code></td>
              <td>{{ table.readonly ? 'oui' : 'non' }}</td>
              <td>{{ table.sessionExpired ? 'expirée' : 'active' }}</td>
              <td>{{ formatSeconds(table.absoluteDelaySeconds) }}</td>
              <td>{{ table.queueSize }}</td>
              <td>{{ table.insertsInQueue }}</td>
              <td>{{ table.lostParts }}</td>
            </tr>
          </tbody>
        </table>
        <p v-if="report.replicatedTables.truncated" class="meta">La liste est limitée aux 200 tables les plus en retard.</p>
      </section>

      <section v-if="report.keyValue" class="group" aria-labelledby="kv-title">
        <h2 id="kv-title">Lien avec la source</h2>
        <dl class="facts">
          <div v-if="report.keyValue.linkStatus !== null"><dt>Lien</dt><dd>{{ linkLabel(report.keyValue.linkStatus) }}</dd></div>
          <div v-if="report.keyValue.lastIoSecondsAgo !== null"><dt>Dernier échange avec la source</dt><dd>il y a {{ formatSeconds(report.keyValue.lastIoSecondsAgo) }}</dd></div>
          <div v-if="report.keyValue.syncInProgress"><dt>Synchronisation complète</dt><dd>en cours</dd></div>
          <div><dt>Tampon de réplication</dt><dd>{{ formatBytes(report.keyValue.backlogSize) }}</dd></div>
          <div><dt>Décalage de la source</dt><dd><code>{{ report.keyValue.masterReplOffset ?? '—' }}</code></dd></div>
        </dl>
        <p class="meta">L’adresse, le port et le mot de passe de la source ne sont jamais affichés.</p>
      </section>

      <section v-if="report.role === 'source' || report.role === 'replica'" class="group" aria-labelledby="settings-title">
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
.grid { width: 100%; border-collapse: collapse; font-size: 13px; }
.grid th { text-align: left; color: var(--mut); font-weight: 500; padding: 4px 10px 4px 0; }
.grid td { padding: 4px 10px 4px 0; border-top: 1px solid var(--line); overflow-wrap: anywhere; }
.grid tr.idle td { color: var(--bad); }
.alerts { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 6px; }
.alerts li { padding: 10px 12px; border-radius: var(--radius); background: var(--sec-bg); border: 1px solid var(--sec-line); color: var(--sec-ink); font-size: 13px; }
.alerts li.critical { background: var(--bad-bg); border-color: var(--bad-line); color: var(--bad); }
.clean { padding: 12px 14px; border-radius: var(--radius); background: var(--ok-bg); border: 1px solid var(--ok-line); color: var(--ok); }
.note { padding: 10px 12px; border-radius: var(--radius); background: var(--sec-bg); border: 1px solid var(--sec-line); color: var(--sec-ink); font-size: 13px; }
.error { padding: 10px 12px; border-radius: var(--radius); background: var(--bad-bg); border: 1px solid var(--bad-line); color: var(--bad); font-size: 13px; }
</style>
