<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { messageFor } from '../api/errors'
import { fetchMigrations, runMigrationAction } from '../api/migrations'
import type { MigrationActionName, MigrationReport, VitessMigration } from '../api/types'
import AppButton from '../components/AppButton.vue'
import ConfirmDialog from '../components/ConfirmDialog.vue'
import { useWorkspaceStore } from '../stores/workspace'
import { describeStatus } from '../utils/diagnostics'

const props = defineProps<{ id: string }>()
const workspace = useWorkspaceStore()

const report = ref<MigrationReport | null>(null)
const failure = ref<string | null>(null)
const loading = ref(false)
const pending = ref<{ label: string; statement: string } | null>(null)
const busy = ref(false)

const STATUSES: Record<VitessMigration['status'], string> = { queued: 'en file', ready: 'prête', running: 'en cours', complete: 'terminée', failed: 'échouée', cancelled: 'annulée', unknown: 'état inconnu' }
const ACTIONS: Record<MigrationActionName, string> = { launch: 'Lancer', cancel: 'Annuler', retry: 'Réessayer', complete: 'Terminer' }
const note = computed(() => (report.value === null ? null : describeStatus(report.value.status, 'les migrations')))

async function load(): Promise<void> {
  loading.value = true
  try {
    report.value = await fetchMigrations(props.id, workspace.diagnosticScope())
    failure.value = null
  } catch (error) {
    report.value = null
    failure.value = messageFor(error)
  } finally {
    loading.value = false
  }
}

onMounted(load)

// Une action est une instruction `ALTER VITESS_MIGRATION` : elle part par la console, qui demande sa confirmation avant d'agir.
async function ask(migration: VitessMigration, action: { action: MigrationActionName; statement: string }): Promise<void> {
  failure.value = null
  try {
    if ((await runMigrationAction(props.id, action.statement, false, workspace.diagnosticScope())) === 'needs_confirmation') {
      pending.value = { label: `${ACTIONS[action.action]} la migration de ${migration.table}`, statement: action.statement }
      return
    }
    await load()
  } catch (error) {
    failure.value = messageFor(error)
  }
}

async function confirm(): Promise<void> {
  const current = pending.value
  if (current === null) return
  busy.value = true
  try {
    await runMigrationAction(props.id, current.statement, true, workspace.diagnosticScope())
    pending.value = null
    await load()
  } catch (error) {
    pending.value = null
    failure.value = messageFor(error)
  } finally {
    busy.value = false
  }
}

const when = (value: string | null): string => value ?? '—'
</script>

<template>
  <section class="migrations" aria-label="Migrations de DDL en ligne">
    <header>
      <h2>Migrations de DDL en ligne</h2>
      <AppButton :disabled="loading" @click="load">Actualiser</AppButton>
    </header>
    <p class="note">Les migrations du keyspace lues sur le serveur. Une action est une instruction <code>ALTER VITESS_MIGRATION</code> : elle s’exécute avec votre compte, après confirmation.</p>

    <p v-if="failure" class="error" role="alert">{{ failure }}</p>
    <p v-else-if="loading && report === null" class="note">Lecture des migrations…</p>
    <p v-if="note" class="note">{{ note }}</p>

    <template v-if="report && !note">
      <p v-if="report.truncated" class="note warn">Seules les plus récentes sont listées.</p>
      <p v-if="report.migrations.length === 0" class="note">Aucune migration de DDL en ligne sur ce keyspace.</p>
      <table v-else>
        <thead><tr><th>Table</th><th>État</th><th>Progression</th><th>Ajoutée</th><th>Instruction</th><th /></tr></thead>
        <tbody>
          <tr v-for="migration in report.migrations" :key="`${migration.uuid}/${migration.shard}`" data-test="migration-row" :class="{ bad: migration.status === 'failed' }">
            <td>{{ migration.table }} <span class="mut">{{ migration.keyspace }}/{{ migration.shard }}</span></td>
            <td>{{ STATUSES[migration.status] }}<span v-if="migration.retries > 0" class="mut"> ({{ migration.retries }} reprise{{ migration.retries > 1 ? 's' : '' }})</span></td>
            <td>{{ migration.progress }} %</td>
            <td>{{ when(migration.addedAt) }}</td>
            <td>
              <code>{{ migration.statement ?? '—' }}</code>
              <p v-if="migration.message" class="mut">{{ migration.message }}</p>
            </td>
            <td class="actions">
              <AppButton v-for="action in migration.actions" :key="action.action" data-test="migration-action" variant="ghost" @click="ask(migration, action)">{{ ACTIONS[action.action] }}</AppButton>
            </td>
          </tr>
        </tbody>
      </table>
    </template>

    <ConfirmDialog
      v-if="pending"
      title="Confirmer cette action ?"
      :message="`${pending.label}. L’instruction suivante sera envoyée au serveur : ${pending.statement}`"
      confirm-label="Exécuter"
      danger
      :busy="busy"
      @confirm="confirm"
      @cancel="pending = null"
    />
  </section>
</template>

<style scoped>
.migrations { display: grid; gap: 12px; padding: 16px; }
header { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
h2 { margin: 0; font-family: var(--font-display); }
.note { margin: 0; color: var(--mut); font-size: 13px; }
.warn { color: var(--warn); }
.error { color: var(--danger); }
table { width: 100%; border-collapse: collapse; font-size: 13px; }
th, td { padding: 6px 10px; text-align: left; border-bottom: 1px solid var(--line); vertical-align: top; }
.mut { color: var(--mut); }
.bad td:nth-child(2) { color: var(--danger); }
code { font-family: var(--font-mono); font-size: 12px; word-break: break-word; }
.actions { white-space: nowrap; }
</style>
