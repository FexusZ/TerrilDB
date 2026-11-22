<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { fetchTabletComparison } from '../api/diagnostics'
import { messageFor } from '../api/errors'
import type { TabletComparison, TabletReading } from '../api/types'
import AppButton from '../components/AppButton.vue'
import { useWorkspaceStore } from '../stores/workspace'
import { describeStatus, formatSeconds } from '../utils/diagnostics'
import { threadLabel } from '../utils/replication'

const props = defineProps<{ id: string }>()
const workspace = useWorkspaceStore()

const comparison = ref<TabletComparison | null>(null)
const failure = ref<string | null>(null)
const loading = ref(false)

const ROLES: Record<string, string> = { PRIMARY: 'primaire', REPLICA: 'réplica', RDONLY: 'lecture seule' }
const readable = computed(() => (comparison.value?.tablets ?? []).filter((tablet) => tablet.failure === null && tablet.variablesStatus?.availability === 'available'))

async function load(): Promise<void> {
  loading.value = true
  failure.value = null
  try {
    comparison.value = await fetchTabletComparison(props.id, workspace.diagnosticScope())
  } catch (error) {
    comparison.value = null
    failure.value = messageFor(error)
  } finally {
    loading.value = false
  }
}

onMounted(load)

/** La réplication d'un tablet en une phrase : un tablet illisible ne passe jamais pour un tablet sain. */
function replicationText(tablet: TabletReading): string {
  if (tablet.failure !== null) return 'illisible'
  const report = tablet.replication
  if (report === null) return 'non lue'
  const note = describeStatus(report.status, 'la réplication')
  if (note !== null) return 'non lisible'
  if (report.role === 'none') return 'aucune réplication'
  if (report.role === 'source') return `source${report.replicaCount === null ? '' : `, ${String(report.replicaCount)} réplica${report.replicaCount > 1 ? 's' : ''}`}`
  const channel = report.channels[0]
  if (channel === undefined) return 'réplica sans canal'
  const lag = channel.lagSeconds === null ? 'retard inconnu' : `retard ${formatSeconds(channel.lagSeconds)}`

  return `réception ${threadLabel(channel.receiver)}, application ${threadLabel(channel.applier)}, ${lag}`
}

const alertCount = (tablet: TabletReading): number => tablet.replication?.alerts.length ?? 0
const alertText = (tablet: TabletReading): string => {
  const count = alertCount(tablet)

  return count === 0 ? '' : `${String(count)} alerte${count > 1 ? 's' : ''}`
}
const valueText = (value: string | null | undefined, unit: string | null): string => (value === null || value === undefined ? 'défaut' : unit === null ? value : `${value} ${unit}`)
</script>

<template>
  <section class="compare" aria-label="Comparaison des tablets">
    <header>
      <h2>Tablets côte à côte</h2>
      <AppButton :disabled="loading" @click="load">Actualiser</AppButton>
    </header>
    <p class="note">Lecture seule : la réplication de chaque tablet du keyspace et les variables modifiées dont la valeur diffère. Une variable qui n’est pas modifiée sur un tablet y est à son défaut.</p>

    <p v-if="failure" class="error" role="alert">{{ failure }}</p>
    <p v-else-if="loading && comparison === null" class="note">Lecture des tablets…</p>

    <template v-if="comparison">
      <p v-if="comparison.truncated" class="note warn">Les tablets ne sont pas tous lus (au plus huit, le primaire d’abord) : chaque tablet lu ouvre une session.</p>
      <table class="tablets">
        <thead><tr><th>Tablet</th><th>Rôle</th><th>Service</th><th>Réplication</th><th>Variables modifiées</th></tr></thead>
        <tbody>
          <tr v-for="tablet in comparison.tablets" :key="tablet.alias" data-test="tablet-row" :class="{ bad: tablet.failure !== null }">
            <td>{{ tablet.alias }} <span class="mut">{{ tablet.keyspace }}/{{ tablet.shard }}</span></td>
            <td>{{ ROLES[tablet.type] ?? tablet.type.toLowerCase() }}</td>
            <td>{{ tablet.serving ? 'en service' : 'hors service' }}</td>
            <td>{{ replicationText(tablet) }} <strong v-if="alertCount(tablet) > 0" class="alert">{{ alertText(tablet) }}</strong></td>
            <td>{{ tablet.changedVariables === null ? '—' : tablet.changedVariables }}</td>
          </tr>
        </tbody>
      </table>

      <h3>Écarts de configuration</h3>
      <p v-if="readable.length < 2" class="note">Il faut au moins deux tablets lisibles pour comparer leurs variables.</p>
      <p v-else-if="comparison.differences.length === 0" class="note">Aucun écart : les variables modifiées ont la même valeur sur les tablets lus.</p>
      <table v-else class="differences">
        <thead><tr><th>Variable</th><th v-for="tablet in readable" :key="tablet.alias">{{ tablet.alias }}</th></tr></thead>
        <tbody>
          <tr v-for="difference in comparison.differences" :key="difference.name" data-test="difference-row">
            <td>{{ difference.name }}</td>
            <td v-for="tablet in readable" :key="tablet.alias" :class="{ dflt: difference.values[tablet.alias] === null }">{{ valueText(difference.values[tablet.alias], difference.unit) }}</td>
          </tr>
        </tbody>
      </table>
      <p v-if="comparison.masked > 0" class="note">{{ comparison.masked }} variable{{ comparison.masked > 1 ? 's' : '' }} masquée{{ comparison.masked > 1 ? 's' : '' }} (secrets) : jamais comparée{{ comparison.masked > 1 ? 's' : '' }} ni montrée{{ comparison.masked > 1 ? 's' : '' }}.</p>
    </template>
  </section>
</template>

<style scoped>
.compare { display: grid; gap: 12px; padding: 16px; }
header { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
h2, h3 { margin: 0; font-family: var(--font-display); }
.note { margin: 0; color: var(--mut); font-size: 13px; }
.warn { color: var(--warn); }
.error { color: var(--danger); }
table { width: 100%; border-collapse: collapse; font-size: 13px; }
th, td { padding: 6px 10px; text-align: left; border-bottom: 1px solid var(--line); }
.mut { color: var(--mut); }
.bad td { color: var(--danger); }
.alert { color: var(--warn); margin-left: 6px; }
.dflt { color: var(--mut); font-style: italic; }
</style>
