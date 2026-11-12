<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { messageFor } from '../api/errors'
import { fetchLocks, fetchSessions } from '../api/diagnostics'
import type { LockReport, SessionReport } from '../api/types'
import AppButton from '../components/AppButton.vue'
import { useWorkspaceStore } from '../stores/workspace'
import { describeStatus, flattenBlocking, formatSeconds, isLongTransaction } from '../utils/diagnostics'

const props = defineProps<{ id: string }>()
const workspace = useWorkspaceStore()

const sessions = ref<SessionReport | null>(null)
const locks = ref<LockReport | null>(null)
const sessionsError = ref<string | null>(null)
const locksError = ref<string | null>(null)
const loading = ref(false)
// Seuil de « transaction trop longue » : réglable ici, jamais enregistré.
const threshold = ref(60)

const sessionsNote = computed(() => (sessions.value === null ? null : describeStatus(sessions.value.status, 'les sessions')))
const locksNote = computed(() => (locks.value === null ? null : describeStatus(locks.value.status, 'les verrous et les transactions')))
const sessionRows = computed(() => [...(sessions.value?.sessions ?? [])].sort((a, b) => (b.seconds ?? -1) - (a.seconds ?? -1)))
const blockingLines = computed(() => flattenBlocking(locks.value?.blocking ?? []))
const longCount = computed(() => (locks.value?.transactions ?? []).filter((transaction) => isLongTransaction(transaction, threshold.value)).length)

function scope() {
  return { database: workspace.database, schema: workspace.schema }
}

async function load(): Promise<void> {
  loading.value = true
  sessionsError.value = null
  locksError.value = null
  // Deux lectures indépendantes : l'échec de l'une ne cache pas l'autre.
  await Promise.all([
    fetchSessions(props.id, scope()).then(
      (report) => (sessions.value = report),
      (failure: unknown) => {
        sessions.value = null
        sessionsError.value = messageFor(failure)
      },
    ),
    fetchLocks(props.id, scope()).then(
      (report) => (locks.value = report),
      (failure: unknown) => {
        locks.value = null
        locksError.value = messageFor(failure)
      },
    ),
  ])
  loading.value = false
}

onMounted(load)

const who = (id: string, user: string | null): string => `Session ${id}${user === null ? '' : ` (${user})`}`
</script>

<template>
  <section class="pane">
    <div class="head">
      <h1>Activité du serveur</h1>
      <p class="meta">Lecture seule, à la demande : rien n’est arrêté ni modifié depuis cette page. Les requêtes des autres sessions sont masquées (valeurs remplacées par ?) puis raccourcies.</p>
      <div class="tools">
        <AppButton small :busy="loading" @click="load">Actualiser</AppButton>
        <label class="threshold">Transaction longue à partir de <input v-model.number="threshold" name="threshold" type="number" min="0" step="10"> s</label>
      </div>
    </div>

    <section class="group" aria-labelledby="blocking-title">
      <h2 id="blocking-title">Qui bloque qui</h2>
      <p v-if="locksError" class="error" role="alert">{{ locksError }}</p>
      <p v-else-if="!locks" class="meta" role="status">Lecture en cours…</p>
      <p v-else-if="locksNote" class="note" role="status">{{ locksNote }}</p>
      <template v-else>
        <p v-if="locks.truncated" class="note" role="status">Le serveur en a davantage : seules les 200 premières lignes de chaque liste sont montrées.</p>
        <p v-if="locks.blockingTruncated" class="note" role="status">L’arbre est borné : des sessions ne sont pas montrées. Chaque session apparaît une seule fois, sous le bloqueur le plus proche.</p>
        <p v-if="blockingLines.length === 0" class="clean">Aucune attente de verrou.</p>
        <ul v-else class="tree" data-testid="blocking">
          <li v-for="(line, index) in blockingLines" :key="index" :style="{ paddingLeft: `${String(line.depth * 24)}px` }">
            <p class="line">
              <strong>{{ who(line.node.id, line.node.user) }}</strong>
              <template v-if="line.node.seconds === null && line.node.kind === null">
                bloque {{ line.node.blocked.length }} {{ line.node.blocked.length > 1 ? 'sessions' : 'session' }}
              </template>
              <template v-else>
                attend<template v-if="line.node.seconds !== null"> depuis {{ formatSeconds(line.node.seconds) }}</template>
                <template v-if="line.node.kind === 'metadata'"> · verrou de métadonnées</template>
                <template v-if="line.node.mode"> · <code>{{ line.node.mode }}</code></template>
                <template v-if="line.node.object"> · <code>{{ line.node.object }}</code></template>
              </template>
            </p>
            <pre v-if="line.node.query">{{ line.node.query }}</pre>
            <p v-else-if="line.node.queryReason === 'redacted'" class="meta">Texte non montré (impossible à masquer sans risque).</p>
          </li>
        </ul>
      </template>
    </section>

    <section class="group" aria-labelledby="transactions-title">
      <h2 id="transactions-title">Transactions ouvertes<template v-if="locks && !locksNote"> · {{ locks.transactions.length }}</template></h2>
      <template v-if="locks && !locksNote">
        <p v-if="locks.transactions.length === 0" class="clean">Aucune transaction ouverte.</p>
        <template v-else>
          <p v-if="longCount > 0" class="note" role="status">{{ longCount }} {{ longCount > 1 ? 'transactions durent' : 'transaction dure' }} depuis {{ formatSeconds(threshold) }} ou plus : une transaction oubliée garde ses verrous.</p>
          <div class="scroll">
            <table data-testid="transactions">
              <thead><tr><th>Session</th><th>Utilisateur</th><th>Âge</th><th>État</th><th>Lignes verrouillées</th><th>Lignes modifiées</th><th>Requête</th></tr></thead>
              <tbody>
                <tr v-for="transaction in locks.transactions" :key="transaction.session" :class="{ long: isLongTransaction(transaction, threshold) }">
                  <td>{{ transaction.session }}</td>
                  <td>{{ transaction.user ?? '' }}</td>
                  <td>{{ formatSeconds(transaction.seconds) }}</td>
                  <td>{{ transaction.sleeping ? 'en sommeil' : (transaction.state ?? '') }}</td>
                  <td>{{ transaction.rowsLocked ?? '' }}</td>
                  <td>{{ transaction.rowsModified ?? '' }}</td>
                  <td class="query">{{ transaction.query ?? (transaction.queryReason === 'redacted' ? 'Texte non montré' : '') }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </template>
      </template>
      <p v-else-if="locks" class="note" role="status">{{ locksNote }}</p>
    </section>

    <section class="group" aria-labelledby="sessions-title">
      <h2 id="sessions-title">Sessions<template v-if="sessions && !sessionsNote"> · {{ sessions.sessions.length }}</template></h2>
      <p v-if="sessionsError" class="error" role="alert">{{ sessionsError }}</p>
      <p v-else-if="!sessions" class="meta" role="status">Lecture en cours…</p>
      <p v-else-if="sessionsNote" class="note" role="status">{{ sessionsNote }}</p>
      <template v-else>
        <p v-if="sessions.truncated" class="note" role="status">Le serveur en a davantage : seules les 200 premières sessions sont montrées.</p>
        <div class="scroll">
          <table data-testid="sessions">
            <thead><tr><th>Session</th><th>Utilisateur</th><th>Hôte</th><th>Base</th><th>État</th><th>Durée</th><th>Requête</th></tr></thead>
            <tbody>
              <tr v-for="session in sessionRows" :key="session.id">
                <td>{{ session.id }}<span v-if="session.current" class="me"> TerrilDB</span></td>
                <td>{{ session.user ?? '' }}</td>
                <td>{{ session.client ?? '' }}</td>
                <td>{{ session.database ?? '' }}</td>
                <td>{{ session.state ?? '' }}</td>
                <td>{{ session.seconds === null ? '' : formatSeconds(session.seconds) }}</td>
                <td class="query">{{ session.query ?? '' }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </template>
    </section>
  </section>
</template>

<style scoped>
.pane { flex: 1; min-width: 0; overflow: auto; padding: 24px 28px 48px; display: flex; flex-direction: column; gap: 22px; }
h1 { font-size: 30px; line-height: 1.1; font-weight: 600; }
h2 { font-size: 15px; font-weight: 600; margin-bottom: 8px; }
.head { display: flex; flex-direction: column; gap: 8px; }
.tools { display: flex; flex-wrap: wrap; gap: 16px; align-items: center; }
.threshold { color: var(--mut); font-size: 13px; display: inline-flex; gap: 6px; align-items: center; }
.threshold input { width: 72px; height: 32px; padding: 0 8px; border-radius: var(--radius); border: 1px solid var(--line); background: var(--s1); color: var(--fg); font: 400 13px var(--font-ui); }
.meta { color: var(--mut); font-size: 13px; }
.tree { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 8px; }
.line { display: flex; flex-wrap: wrap; gap: 6px; align-items: baseline; }
code { font: 400 12.5px var(--font-mono); }
pre { margin: 4px 0 0; padding: 6px 10px; border-radius: var(--radius); background: var(--bg); border: 1px solid var(--line); font: 400 12.5px/1.5 var(--font-mono); overflow-x: auto; max-width: 100%; }
.scroll { overflow-x: auto; }
table { border-collapse: collapse; width: 100%; font-size: 13px; }
th, td { text-align: left; padding: 6px 10px; border-bottom: 1px solid var(--line); vertical-align: top; }
th { color: var(--mut); font-weight: 500; white-space: nowrap; }
.query { font-family: var(--font-mono); font-size: 12px; max-width: 420px; overflow-wrap: anywhere; }
tr.long { box-shadow: inset 3px 0 0 var(--sec); background: var(--sec-bg); }
.me { color: var(--acc); font-weight: 500; }
.clean { padding: 12px 14px; border-radius: var(--radius); background: var(--ok-bg); border: 1px solid var(--ok-line); color: var(--ok); }
.note { padding: 10px 12px; border-radius: var(--radius); background: var(--sec-bg); border: 1px solid var(--sec-line); color: var(--sec-ink); font-size: 13px; }
.error { padding: 10px 12px; border-radius: var(--radius); background: var(--bad-bg); border: 1px solid var(--bad-line); color: var(--bad); font-size: 13px; }
</style>
