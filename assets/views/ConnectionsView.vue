<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { messageFor } from '../api/errors'
import { CONNECTION_COLORS, ENGINE_BADGES, ENGINE_LABELS, type ConnectionColor, type ConnectionSummary, type Engine } from '../api/types'
import AppHeader from '../components/AppHeader.vue'
import ConfirmDialog from '../components/ConfirmDialog.vue'
import { useConnectionsStore } from '../stores/connections'
import { formatDate } from '../utils/format'

const store = useConnectionsStore()
const engine = ref<Engine | 'all'>('all')
const search = ref('')
const error = ref<string | null>(null)
const toDelete = ref<ConnectionSummary | null>(null)
const deleting = ref(false)

const filters: { value: Engine | 'all'; label: string }[] = [
  { value: 'all', label: 'Tous' },
  { value: 'mysql', label: ENGINE_LABELS.mysql },
  { value: 'mariadb', label: ENGINE_LABELS.mariadb },
  { value: 'postgresql', label: ENGINE_LABELS.postgresql },
  { value: 'clickhouse', label: ENGINE_LABELS.clickhouse },
  { value: 'redis', label: ENGINE_LABELS.redis },
]

const colorLabel = (color: ConnectionColor): string => CONNECTION_COLORS.find((candidate) => candidate.value === color)?.label ?? color

function normalize(text: string): string {
  return text.normalize('NFD').replace(/\p{Diacritic}/gu, '').toLowerCase()
}

const visible = computed(() =>
  store.items.filter((connection) => (engine.value === 'all' || connection.engine === engine.value) && normalize(connection.name).includes(normalize(search.value.trim()))),
)

onMounted(async () => {
  try {
    await store.load()
  } catch (failure) {
    error.value = messageFor(failure)
  }
})

async function confirmDelete(): Promise<void> {
  if (toDelete.value === null) {
    return
  }
  deleting.value = true
  try {
    await store.remove(toDelete.value.id)
    toDelete.value = null
  } catch (failure) {
    error.value = messageFor(failure)
    toDelete.value = null
  } finally {
    deleting.value = false
  }
}
</script>

<template>
  <AppHeader />
  <main class="page">
    <div class="head">
      <div class="title">
        <h1>Mes connexions</h1>
        <p v-if="store.loaded">{{ store.items.length }} {{ store.items.length > 1 ? 'bases enregistrées' : 'base enregistrée' }}. Leurs identifiants sont chiffrés avec votre clé.</p>
      </div>
      <RouterLink :to="{ name: 'connection-new' }" class="new">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14" /></svg>
        Nouvelle connexion
      </RouterLink>
    </div>

    <p v-if="error" class="error" role="alert">{{ error }}</p>

    <div class="filters">
      <label class="sr-only" for="search">Rechercher une connexion</label>
      <input id="search" v-model="search" type="search" placeholder="Rechercher une connexion">
      <button
        v-for="filter in filters"
        :key="filter.value"
        type="button"
        class="chip"
        :class="{ on: engine === filter.value }"
        :data-engine="filter.value"
        :aria-pressed="engine === filter.value"
        @click="engine = filter.value"
      >
        {{ filter.label }}
      </button>
    </div>

    <div v-if="store.loaded && store.items.length === 0" class="empty">
      <p>Aucune connexion. Ajoutez la première base à gérer.</p>
      <RouterLink :to="{ name: 'connection-new' }">Ajouter une base</RouterLink>
    </div>
    <p v-else-if="store.loaded && visible.length === 0" class="empty">Aucune connexion ne correspond à votre recherche.</p>

    <div v-else class="grid">
      <article v-for="connection in visible" :key="connection.id" class="card">
        <div class="who">
          <span class="badge" aria-hidden="true">{{ ENGINE_BADGES[connection.engine] }}</span>
          <div>
            <h3>
              <span v-if="connection.color" class="swatch" :data-color="connection.color" role="img" :aria-label="`Couleur : ${colorLabel(connection.color)}`" />
              {{ connection.name }}
            </h3>
            <p class="meta">{{ ENGINE_LABELS[connection.engine] }}</p>
          </div>
        </div>
        <div class="row">
          <span class="chip secret">Chiffrée</span>
          <span class="meta">Modifiée le {{ formatDate(connection.updatedAt) }}</span>
        </div>
        <div class="actions">
          <RouterLink class="open" :to="{ name: 'workspace', params: { id: connection.id } }">Ouvrir</RouterLink>
          <RouterLink class="edit" :to="{ name: 'connection-edit', params: { id: connection.id } }">Modifier</RouterLink>
          <button type="button" class="delete" :aria-label="`Supprimer ${connection.name}`" @click="toDelete = connection">Supprimer</button>
        </div>
      </article>
    </div>

    <ConfirmDialog
      v-if="toDelete"
      :title="`Supprimer « ${toDelete.name} » ?`"
      message="La connexion et ses identifiants chiffrés sont supprimés de TerrilDB. La base elle-même n’est pas touchée."
      confirm-label="Supprimer"
      danger
      :busy="deleting"
      @confirm="confirmDelete"
      @cancel="toDelete = null"
    />
  </main>
</template>

<style scoped>
.page { max-width: 1200px; margin: 0 auto; padding: 40px 32px 64px; display: flex; flex-direction: column; gap: 28px; }
.head { display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: 16px; }
.title { display: flex; flex-direction: column; gap: 4px; }
h1 { font-size: 30px; font-weight: 700; letter-spacing: -0.01em; }
.title p, .meta { color: var(--mut); }
.meta { font-size: 12px; }
.new { display: inline-flex; align-items: center; gap: 8px; height: 40px; padding: 0 16px; border-radius: var(--radius); background: var(--acc); color: var(--acc-ink); font-weight: 600; text-decoration: none; }
.error { padding: 10px 12px; border-radius: var(--radius); background: var(--bad-bg); border: 1px solid var(--bad-line); color: var(--bad); font-size: 13px; }
.filters { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; }
.filters input { flex: 1 1 240px; max-width: 360px; height: 40px; padding: 0 12px; border-radius: var(--radius); border: 1px solid var(--line); background: var(--s1); color: var(--fg); font: inherit; }
.chip { display: inline-flex; align-items: center; height: 26px; padding: 0 10px; border-radius: 13px; border: 1px solid var(--line); background: transparent; color: var(--mut); font: 400 12px var(--font-ui); cursor: pointer; }
.chip.on { color: var(--acc); border-color: var(--acc-line); background: var(--acc-bg); }
.chip.secret { color: var(--sec); border-color: var(--sec-line); background: var(--sec-bg); cursor: default; }
.grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(100%, 340px), 1fr)); gap: 16px; }
.card { display: flex; flex-direction: column; gap: 16px; padding: 20px; border-radius: 8px; border: 1px solid var(--line); background: var(--s1); }
.card:hover { border-color: var(--line-strong); }
.who { display: flex; gap: 14px; align-items: center; }
.badge { flex: none; width: 40px; height: 40px; border-radius: var(--radius); border: 1px solid var(--line); background: var(--s3); display: flex; align-items: center; justify-content: center; font: 500 13px var(--font-mono); color: var(--acc); }
h3 { display: flex; align-items: center; gap: 8px; font-size: 16px; font-weight: 600; }
.swatch { width: 12px; height: 12px; border-radius: 3px; flex: none; background: var(--mut); }
.swatch[data-color='red'] { background: var(--conn-red); }
.swatch[data-color='orange'] { background: var(--conn-orange); }
.swatch[data-color='yellow'] { background: var(--conn-yellow); }
.swatch[data-color='green'] { background: var(--conn-green); }
.swatch[data-color='teal'] { background: var(--conn-teal); }
.swatch[data-color='blue'] { background: var(--conn-blue); }
.swatch[data-color='violet'] { background: var(--conn-violet); }
.swatch[data-color='pink'] { background: var(--conn-pink); }
.row { display: flex; align-items: center; justify-content: space-between; gap: 8px; flex-wrap: wrap; }
.actions { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.actions a, .actions button { display: inline-flex; align-items: center; height: 34px; padding: 0 14px; border-radius: var(--radius); font: 500 13px var(--font-ui); text-decoration: none; cursor: pointer; border: 1px solid var(--line); background: transparent; color: var(--fg); }
.actions .open { background: var(--acc); border-color: var(--acc); color: var(--acc-ink); font-weight: 600; }
.actions .delete { margin-left: auto; color: var(--bad); border-color: transparent; }
.empty { display: flex; flex-direction: column; align-items: center; gap: 8px; padding: 48px 16px; border: 1px dashed var(--line-strong); border-radius: 8px; color: var(--mut); text-align: center; }
</style>
