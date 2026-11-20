<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { messageFor } from '../api/errors'
import { fetchLogs, type LogsReport } from '../api/logs'
import AppButton from '../components/AppButton.vue'
import { useWorkspaceStore } from '../stores/workspace'
import { describeStatus, enableLogHint } from '../utils/diagnostics'

const props = defineProps<{ id: string }>()
const workspace = useWorkspaceStore()

const report = ref<LogsReport | null>(null)
const failure = ref<string | null>(null)
const loading = ref(false)

const errorNote = computed(() => (report.value === null ? null : describeStatus(report.value.errorLog.status, 'le journal d’erreurs')))
const slowNote = computed(() => (report.value === null ? null : describeStatus(report.value.slowQueries.status, 'le journal des requêtes lentes')))
const slowHint = computed(() => (report.value === null ? null : enableLogHint(report.value.slowQueries.status)))

async function load(): Promise<void> {
  loading.value = true
  failure.value = null
  try {
    report.value = await fetchLogs(props.id, workspace.diagnosticScope())
  } catch (error) {
    report.value = null
    failure.value = messageFor(error)
  } finally {
    loading.value = false
  }
}

const grouped = (value: number): string => String(Math.round(value)).replace(/\B(?=(\d{3})+(?!\d))/g, ' ')
const when = (instant: string | null): string => (instant === null ? '—' : instant.replace('T', ' ').replace(/\.\d+Z$/, ' UTC'))

onMounted(load)
</script>

<template>
  <section class="pane">
    <div class="head">
      <h1>Journaux du serveur</h1>
      <p class="meta">Lus en SQL, en lecture seule, sans jamais ouvrir un fichier de l’hôte : les 200 lignes les plus récentes. Les textes sont masqués (chaînes, adresses, comptes, littéraux remplacés par ?) puis raccourcis, mais un journal peut encore révéler des noms d’objets : à lire avec soin. Rien n’est conservé ni modifié, et aucun texte n’entre dans le journal d’audit.</p>
      <div class="tools">
        <AppButton small :busy="loading" @click="load">Actualiser</AppButton>
      </div>
    </div>

    <p v-if="failure" class="error" role="alert">{{ failure }}</p>
    <template v-else-if="report">
      <section>
        <h2>Journal d’erreurs</h2>
        <p v-if="errorNote" class="note" role="status" data-testid="error-note">{{ errorNote }}</p>
        <template v-else>
          <p v-if="report.errorLog.truncated" class="meta">Le serveur en a davantage : seules les plus récentes sont montrées.</p>
          <ul class="lines">
            <li v-for="(line, index) in report.errorLog.entries" :key="index" data-testid="error-line" :data-level="line.level">
              <span class="when">{{ when(line.loggedAt) }}</span>
              <span class="level">{{ line.level }}</span>
              <span class="where">{{ [line.subsystem, line.code].filter((part) => part !== null).join(' · ') }}</span>
              <span class="text">{{ line.message ?? '—' }}</span>
            </li>
          </ul>
        </template>
      </section>

      <section>
        <h2>Requêtes lentes</h2>
        <p v-if="slowNote" class="note" role="status" data-testid="slow-note">{{ slowNote }}</p>
        <p v-if="slowHint" class="hint" data-testid="slow-hint">À lancer vous-même, avec un compte autorisé (TerrilDB n’exécute rien) : <code>{{ slowHint }}</code> Pour que ce réglage survive à un redémarrage, il faut aussi l’écrire dans la configuration du serveur.</p>
        <template v-if="!slowNote">
          <p v-if="report.slowQueries.thresholdSeconds !== null" class="meta">Journalisées à partir de {{ report.slowQueries.thresholdSeconds }} s.</p>
          <p v-if="report.slowQueries.truncated" class="meta">Le serveur en a davantage : seules les plus récentes sont montrées.</p>
          <ul class="lines">
            <li v-for="(query, index) in report.slowQueries.queries" :key="index" data-testid="slow-query">
              <span class="when">{{ when(query.startedAt) }}</span>
              <span class="level">{{ query.durationMs === null ? '—' : `${grouped(query.durationMs)} ms` }}</span>
              <span class="where">{{ [query.user, query.database].filter((part) => part !== null && part !== '').join(' · ') }}<template v-if="query.rowsExamined !== null"> · {{ grouped(query.rowsExamined) }} lignes lues</template></span>
              <span class="text">{{ query.query ?? (query.queryReason === 'redacted' ? 'Texte illisible, non montré' : '—') }}</span>
            </li>
          </ul>
        </template>
      </section>
    </template>
  </section>
</template>

<style scoped>
.pane { flex: 1; min-width: 0; overflow: auto; padding: 24px 28px 48px; display: flex; flex-direction: column; gap: 18px; }
h1 { font-size: 30px; line-height: 1.1; font-weight: 600; }
h2 { font-size: 16px; font-weight: 600; margin-bottom: 8px; }
.head { display: flex; flex-direction: column; gap: 8px; }
.tools { display: flex; gap: 16px; align-items: center; }
.meta { color: var(--mut); font-size: 13px; }
.lines { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 4px; }
.lines li { display: grid; grid-template-columns: 11.5em 7em minmax(8em, 14em) 1fr; gap: 12px; padding: 6px 10px; border-radius: var(--radius); border: 1px solid var(--line); background: var(--s1); font-size: 12.5px; }
.lines li[data-level='error'] { border-color: var(--bad-line); background: var(--bad-bg); }
.lines li[data-level='warning'] { border-color: var(--sec-line); background: var(--sec-bg); }
.when, .where { color: var(--mut); }
.text { font-family: var(--font-mono); overflow-wrap: anywhere; }
.hint { color: var(--mut); font-size: 12.5px; margin: 8px 0; }
code { font-family: var(--font-mono); color: var(--fg); }
.note { padding: 10px 12px; border-radius: var(--radius); background: var(--sec-bg); border: 1px solid var(--sec-line); color: var(--sec-ink); font-size: 13px; }
.error { padding: 10px 12px; border-radius: var(--radius); background: var(--bad-bg); border: 1px solid var(--bad-line); color: var(--bad); font-size: 13px; }
@media (max-width: 720px) { .lines li { grid-template-columns: 1fr; gap: 2px; } }
</style>
