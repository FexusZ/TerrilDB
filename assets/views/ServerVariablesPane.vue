<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { fetchVariables } from '../api/diagnostics'
import { messageFor } from '../api/errors'
import type { VariableReport } from '../api/types'
import AppButton from '../components/AppButton.vue'
import { useWorkspaceStore } from '../stores/workspace'
import { describeRestart, describeSource, describeStatus } from '../utils/diagnostics'

const props = defineProps<{ id: string }>()
const workspace = useWorkspaceStore()

const LIMIT = 100
const search = ref('')
const changedOnly = ref(false)
const report = ref<VariableReport | null>(null)
const error = ref<string | null>(null)
const loading = ref(false)

const note = computed(() => (report.value === null ? null : describeStatus(report.value.status, 'les variables de configuration')))

async function load(): Promise<void> {
  loading.value = true
  error.value = null
  try {
    report.value = await fetchVariables(props.id, { database: workspace.database, schema: workspace.schema }, { search: search.value, changedOnly: changedOnly.value, limit: LIMIT })
  } catch (failure) {
    report.value = null
    error.value = messageFor(failure)
  } finally {
    loading.value = false
  }
}

onMounted(load)

const shown = (value: string | null, unit: string | null): string => (value === null ? '' : unit === null ? value : `${value} ${unit}`)
</script>

<template>
  <section class="pane">
    <div class="head">
      <h1>Variables du serveur</h1>
      <p class="meta">Lecture seule : rien n’est modifié depuis cette page. Une variable dont le nom évoque un mot de passe, un secret ou une clé est listée sans sa valeur. Une valeur posée à chaud se perd au redémarrage et ne touche pas les sessions déjà ouvertes.</p>
      <form class="tools" @submit.prevent="load">
        <label>Nom <input v-model="search" name="search" type="search" maxlength="64" placeholder="max_connections"></label>
        <label class="check"><input v-model="changedOnly" name="changed" type="checkbox"> Seulement celles qui diffèrent du défaut</label>
        <AppButton small type="submit" :busy="loading">Chercher</AppButton>
      </form>
    </div>

    <p v-if="error" class="error" role="alert">{{ error }}</p>
    <p v-else-if="!report" class="meta" role="status">Lecture en cours…</p>
    <p v-else-if="note" class="note" role="status">{{ note }}</p>
    <template v-else>
      <p v-if="report.truncated" class="note" role="status">Le serveur en a davantage. Seules les {{ LIMIT }} premières sont montrées : précisez le nom.</p>
      <p v-if="report.variables.length === 0" class="meta">Aucune variable ne correspond.</p>
      <div v-else class="scroll">
        <table data-testid="variables">
          <thead><tr><th>Nom</th><th>Valeur</th><th>Défaut</th><th>Origine</th><th>Redémarrage</th></tr></thead>
          <tbody>
            <tr v-for="variable in report.variables" :key="`${variable.scope ?? ''}/${variable.name}`" :class="{ changed: variable.changed }">
              <td class="name">{{ variable.name }}<span v-if="variable.scope === 'server'" class="tag"> serveur</span></td>
              <td class="value"><em v-if="variable.masked">masquée</em><template v-else>{{ shown(variable.value, variable.unit) }}</template></td>
              <td class="value">{{ variable.masked ? '' : shown(variable.default, variable.unit) }}</td>
              <td>{{ describeSource(variable.source) }}</td>
              <td>{{ describeRestart(variable.restart) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>
  </section>
</template>

<style scoped>
.pane { flex: 1; min-width: 0; overflow: auto; padding: 24px 28px 48px; display: flex; flex-direction: column; gap: 18px; }
h1 { font-size: 30px; line-height: 1.1; font-weight: 600; }
.head { display: flex; flex-direction: column; gap: 8px; }
.meta { color: var(--mut); font-size: 13px; max-width: 78ch; }
.tools { display: flex; flex-wrap: wrap; gap: 16px; align-items: center; }
.tools label { color: var(--mut); font-size: 13px; display: inline-flex; gap: 6px; align-items: center; }
.tools input[type='search'] { width: 220px; height: 32px; padding: 0 8px; border-radius: var(--radius); border: 1px solid var(--line); background: var(--s1); color: var(--fg); font: 400 13px var(--font-mono); }
.scroll { overflow-x: auto; }
table { border-collapse: collapse; width: 100%; font-size: 13px; }
th, td { text-align: left; padding: 6px 10px; border-bottom: 1px solid var(--line); vertical-align: top; }
th { color: var(--mut); font-weight: 500; white-space: nowrap; }
.name { font-family: var(--font-mono); font-size: 12.5px; white-space: nowrap; }
.value { font-family: var(--font-mono); font-size: 12px; max-width: 360px; overflow-wrap: anywhere; }
.tag { color: var(--mut); font: 400 11px var(--font-ui); }
em { color: var(--sec-ink); }
tr.changed { box-shadow: inset 3px 0 0 var(--acc); background: var(--acc-bg); }
.note { padding: 10px 12px; border-radius: var(--radius); background: var(--sec-bg); border: 1px solid var(--sec-line); color: var(--sec-ink); font-size: 13px; }
.error { padding: 10px 12px; border-radius: var(--radius); background: var(--bad-bg); border: 1px solid var(--bad-line); color: var(--bad); font-size: 13px; }
</style>
