<script setup lang="ts">
import { onMounted } from 'vue'
import AppButton from '../components/AppButton.vue'
import AppHeader from '../components/AppHeader.vue'
import { useActivityStore } from '../stores/activity'
import { ACTIVITY_FILTERS, actorLabel, describeEvent } from '../utils/audit'
import { formatDateTime } from '../utils/format'
import type { AuditEntry, AuditOutcome } from '../api/types'

const activity = useActivityStore()

const OUTCOMES: Record<AuditOutcome, string> = { success: 'Réussi', refused: 'Refusé', failed: 'Échec' }

onMounted(() => activity.load(activity.category))

const sqlOf = (entry: AuditEntry): string | null => (typeof entry.details.sql === 'string' ? entry.details.sql : null)
</script>

<template>
  <AppHeader />
  <main class="page">
    <nav aria-label="Fil d’Ariane" class="crumbs">
      <RouterLink :to="{ name: 'connections' }">Mes connexions</RouterLink> / <RouterLink :to="{ name: 'security' }">Sécurité du compte</RouterLink> / Journal d’activité
    </nav>
    <div class="title">
      <h1>Journal d’activité</h1>
      <p>Les connexions à votre compte, les changements de sécurité et chaque requête lancée, depuis l’interface, l’API ou un assistant MCP. Aucun mot de passe, code ni jeton n’y figure.</p>
    </div>

    <div class="filters" role="group" aria-label="Filtrer par type">
      <button
        v-for="filter in ACTIVITY_FILTERS"
        :key="filter.value"
        type="button"
        class="chip"
        :class="{ on: activity.category === filter.value }"
        :data-filter="filter.value || 'all'"
        :aria-pressed="activity.category === filter.value"
        @click="activity.load(filter.value)"
      >
        {{ filter.label }}
      </button>
    </div>

    <p v-if="activity.error" class="error" role="alert">{{ activity.error }}</p>
    <p v-else-if="!activity.loading && activity.items.length === 0" class="empty">Aucune activité pour ce filtre.</p>

    <ul v-else class="events">
      <li v-for="entry in activity.items" :key="entry.id" class="event" :class="{ failed: entry.outcome === 'failed', refused: entry.outcome === 'refused' }">
        <div class="line">
          <span class="what">{{ describeEvent(entry) }}</span>
          <span class="outcome" :class="entry.outcome">{{ OUTCOMES[entry.outcome] }}</span>
        </div>
        <p class="meta">
          {{ formatDateTime(entry.occurredAt) }} · {{ actorLabel(entry.actor) }}<template v-if="entry.ip"> · <span class="mono">{{ entry.ip }}</span></template>
        </p>
        <pre v-if="sqlOf(entry)">{{ sqlOf(entry) }}</pre>
      </li>
    </ul>

    <div v-if="activity.hasMore"><AppButton class="more" :busy="activity.loading" @click="activity.more()">Voir plus</AppButton></div>
  </main>
</template>

<style scoped>
.page { max-width: 760px; margin: 0 auto; padding: 36px 24px 64px; display: flex; flex-direction: column; gap: 22px; }
.crumbs { color: var(--mut); font-size: 13px; }
.title { display: flex; flex-direction: column; gap: 6px; }
h1 { font-size: 30px; font-weight: 700; letter-spacing: -0.01em; }
.title p { color: var(--mut); max-width: 62ch; }
.filters { display: flex; flex-wrap: wrap; gap: 8px; }
.chip { display: inline-flex; align-items: center; height: 26px; padding: 0 10px; border-radius: 13px; border: 1px solid var(--line); background: transparent; color: var(--mut); font: 400 12px var(--font-ui); cursor: pointer; }
.chip.on { color: var(--acc); border-color: var(--acc-line); background: var(--acc-bg); }
.events { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; border: 1px solid var(--line); border-radius: 8px; background: var(--s1); }
.event { padding: 14px 18px; border-top: 1px solid var(--line); display: flex; flex-direction: column; gap: 4px; }
.event:first-child { border-top: 0; }
.event.failed { box-shadow: inset 3px 0 0 var(--bad); }
.event.refused { box-shadow: inset 3px 0 0 var(--sec); }
.line { display: flex; justify-content: space-between; gap: 12px; align-items: baseline; flex-wrap: wrap; }
.what { font-weight: 500; }
.outcome { font: 500 11px var(--font-mono); padding: 2px 8px; border-radius: 4px; border: 1px solid var(--line); color: var(--mut); }
.outcome.success { color: var(--ok); border-color: var(--ok-line); }
.outcome.failed { color: var(--bad); border-color: var(--bad-line); }
.outcome.refused { color: var(--sec); border-color: var(--sec-line); }
.meta, .empty { color: var(--mut); font-size: 13px; }
.mono { font-family: var(--font-mono); }
pre { margin: 6px 0 0; padding: 8px 10px; border-radius: var(--radius); background: var(--bg); border: 1px solid var(--line); font: 400 12px/1.5 var(--font-mono); overflow-x: auto; white-space: pre-wrap; max-height: 8em; }
.error { padding: 10px 12px; border-radius: var(--radius); background: var(--bad-bg); border: 1px solid var(--bad-line); color: var(--bad); font-size: 13px; }
</style>
