<script setup lang="ts">
import { reasonText } from '../api/errors'
import type { AuditOutcome, HistoryEntry } from '../api/types'
import { actorLabel } from '../utils/audit'
import { formatDateTime } from '../utils/format'
import AppButton from './AppButton.vue'

defineProps<{ entries: HistoryEntry[]; hasMore: boolean; loading: boolean; error: string | null }>()
const emit = defineEmits<{ pick: [sql: string]; more: [] }>()

const OUTCOMES: Record<AuditOutcome, string> = { success: 'Exécutée', refused: 'Refusée', failed: 'En échec' }

const rows = (count: number): string => `${String(count)} ${count > 1 ? 'lignes' : 'ligne'}`

function detail(entry: HistoryEntry): string {
  if (entry.outcome === 'refused') {
    return reasonText(entry.reason)
  }
  if (entry.outcome === 'failed') {
    return entry.reason === 'timeout' ? 'Arrêtée : délai dépassé' : 'Erreur du moteur'
  }

  return [entry.rowCount === null ? '' : rows(entry.rowCount), entry.durationMs === null ? '' : `${String(entry.durationMs)} ms`].filter(Boolean).join(' · ')
}
</script>

<template>
  <section class="history" aria-label="Historique des requêtes">
    <p v-if="error" class="error" role="alert">{{ error }}</p>
    <p v-else-if="!loading && entries.length === 0" class="empty">Aucune requête pour cette connexion.</p>
    <ul v-else class="list">
      <li v-for="entry in entries" :key="entry.id" class="entry" :class="{ failed: entry.outcome === 'failed', refused: entry.outcome === 'refused' }">
        <button type="button" class="pick" :aria-label="`Reprendre la requête : ${entry.sql.slice(0, 60)}`" @click="emit('pick', entry.sql)">
          <pre>{{ entry.sql }}</pre>
          <span class="meta">
            <span class="outcome">{{ OUTCOMES[entry.outcome] }}</span>
            {{ detail(entry) }} · {{ formatDateTime(entry.occurredAt) }} · {{ actorLabel(entry.actor) }}
          </span>
        </button>
      </li>
    </ul>
    <div v-if="hasMore" class="more-row"><AppButton class="more" small :busy="loading" @click="emit('more')">Voir plus</AppButton></div>
  </section>
</template>

<style scoped>
.history { flex: 1; min-height: 0; overflow: auto; }
.list { list-style: none; margin: 0; padding: 0; }
.entry { border-bottom: 1px solid var(--line); }
.entry.failed { box-shadow: inset 3px 0 0 var(--bad); }
.entry.refused { box-shadow: inset 3px 0 0 var(--sec); }
.pick { display: block; width: 100%; padding: 12px 16px; background: none; border: 0; color: inherit; text-align: left; cursor: pointer; font: inherit; }
.pick:hover { background: var(--s2); }
pre { margin: 0 0 6px; font: 400 12.5px/1.5 var(--font-mono); white-space: pre-wrap; max-height: 4.5em; overflow: hidden; color: var(--fg); }
.meta { color: var(--mut); font-size: 12px; }
.outcome { font: 500 11px var(--font-mono); margin-right: 6px; }
.failed .outcome { color: var(--bad); }
.refused .outcome { color: var(--sec); }
.empty { padding: 24px 16px; color: var(--mut); }
.error { margin: 16px; padding: 10px 12px; border-radius: var(--radius); background: var(--bad-bg); border: 1px solid var(--bad-line); color: var(--bad); font-size: 13px; }
.more-row { padding: 12px 16px; }
</style>
