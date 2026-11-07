<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { messageFor } from '../api/errors'
import { ENGINE_BADGES, ENGINE_LABELS } from '../api/types'
import AppHeader from '../components/AppHeader.vue'
import WorkbenchSidebar from '../components/WorkbenchSidebar.vue'
import { useConnectionsStore } from '../stores/connections'
import { useWorkspaceStore } from '../stores/workspace'

const props = defineProps<{ id: string }>()
const route = useRoute()
const router = useRouter()
const connections = useConnectionsStore()
const workspace = useWorkspaceStore()
const error = ref<string | null>(null)
// La page enfant (table, clé, console…) lit l'espace de travail : elle ne se monte qu'une fois la connexion et la base de l'adresse ouvertes.
const ready = ref(false)

const connection = computed(() => connections.byId(props.id))
// Diagramme et audit lisent le schéma : pas de sens sur un moteur clé-valeur. On garde la base et le schéma explorés d'une vue à l'autre.
const hasSchemaViews = computed(() => connection.value !== undefined && connection.value.engine !== 'redis')
const scope = computed(() => ({ ...(workspace.database === null ? {} : { database: workspace.database }), ...(workspace.schema === null ? {} : { schema: workspace.schema }) }))

// La dernière table ouverte : le menu garde un accès à sa structure quand on part vers la console, le diagramme ou l'audit.
const lastTable = ref<{ name: string; query: Record<string, string> } | null>(null)
watch(
  () => [route.name, route.params.id, route.params.name, route.query.database, route.query.schema],
  () => {
    if (route.name !== 'table' || route.params.id !== props.id || typeof route.params.name !== 'string') {
      return
    }
    const query: Record<string, string> = {}
    for (const key of ['database', 'schema'] as const) {
      const value = route.query[key]
      if (typeof value === 'string' && value !== '') {
        query[key] = value
      }
    }
    lastTable.value = { name: route.params.name, query }
  },
  { immediate: true },
)
watch(() => props.id, () => {
  lastTable.value = null
})

async function open(): Promise<void> {
  error.value = null
  ready.value = false
  try {
    if (!connections.loaded) {
      await connections.load()
    }
    if (connections.byId(props.id) === undefined) {
      error.value = 'Connexion introuvable.'
      return
    }
    await workspace.open(props.id)
    await applyRouteDatabase()
    ready.value = true
    // Une connexion qui ne nomme aucune base : on ouvre la première, la console a besoin d'une base où s'exécuter.
    await workspace.loadDatabases()
  } catch (failure) {
    error.value = messageFor(failure)
  }
}

// Une table de l'arbre d'une autre base s'ouvre avec `?database=` (et un schéma PostgreSQL avec `?schema=`) : l'espace de travail explore alors cette base-là.
async function applyRouteDatabase(): Promise<void> {
  const requested = route.query.database
  await workspace.selectDatabase(typeof requested === 'string' && requested !== '' ? requested : null)
  const schema = route.query.schema
  await workspace.selectSchema(typeof schema === 'string' && schema !== '' ? schema : null)
}

// Supprimer depuis la barre la connexion qu'on regarde : il n'y a plus rien à afficher ici.
async function onDeleted(deleted: string): Promise<void> {
  if (deleted === props.id) {
    workspace.close()
    await router.push({ name: 'workspace-home' })
  }
}

onMounted(open)
watch(() => props.id, open)
watch(() => [route.query.database, route.query.schema], () => {
  if (workspace.connectionId === props.id) {
    void applyRouteDatabase()
  }
})
</script>

<template>
  <div class="shell">
    <AppHeader>
      <template #left>
        <RouterLink v-if="connection" :to="{ name: 'workspace', params: { id } }" class="current" :data-color="connection.color ?? undefined">
          <span class="engine" aria-hidden="true">{{ ENGINE_BADGES[connection.engine] }}</span>
          <span class="name">{{ connection.name }}</span>
          <span class="sr-only">{{ ENGINE_LABELS[connection.engine] }}</span>
        </RouterLink>
        <RouterLink :to="{ name: 'connections' }" class="switch">Changer de connexion</RouterLink>
      </template>
    </AppHeader>

    <nav v-if="hasSchemaViews && !error" class="views" :data-color="connection?.color ?? undefined" aria-label="Vues de la connexion">
      <RouterLink :to="{ name: 'workspace', params: { id }, query: scope }" exact-active-class="on">Console</RouterLink>
      <RouterLink v-if="lastTable" :to="{ name: 'table', params: { id, name: lastTable.name }, query: lastTable.query }" active-class="on">Structure</RouterLink>
      <RouterLink :to="{ name: 'diagram', params: { id }, query: scope }" active-class="on">Diagramme</RouterLink>
      <RouterLink :to="{ name: 'audit', params: { id }, query: scope }" active-class="on">Audit du schéma</RouterLink>
    </nav>

    <p v-if="error" class="error" role="alert">{{ error }}</p>
    <div v-else class="body">
      <div class="content">
        <p v-if="workspace.tablesError" class="error" role="alert">{{ workspace.tablesError }}</p>
        <RouterView v-if="ready" />
      </div>
      <WorkbenchSidebar @deleted="onDeleted" />
    </div>
  </div>
</template>

<style scoped>
.shell { display: flex; flex-direction: column; min-height: 100vh; }
.current { display: inline-flex; align-items: center; gap: 10px; padding: 0 12px; height: 34px; border-radius: var(--radius); border: 1px solid var(--line); background: var(--s2); color: var(--fg); text-decoration: none; font-weight: 500; }
.current[data-color] { border-left-width: 4px; }
.current[data-color='red'] { border-left-color: var(--conn-red); }
.current[data-color='orange'] { border-left-color: var(--conn-orange); }
.current[data-color='yellow'] { border-left-color: var(--conn-yellow); }
.current[data-color='green'] { border-left-color: var(--conn-green); }
.current[data-color='teal'] { border-left-color: var(--conn-teal); }
.current[data-color='blue'] { border-left-color: var(--conn-blue); }
.current[data-color='violet'] { border-left-color: var(--conn-violet); }
.current[data-color='pink'] { border-left-color: var(--conn-pink); }
.engine { font: 500 11px var(--font-mono); color: var(--acc); }
.switch { color: var(--mut); font-size: 13px; }
.views { display: flex; gap: 4px; padding: 0 16px; border-bottom: 1px solid var(--line); background: var(--s1); }
.views[data-color='red'] { --tint: var(--conn-red); }
.views[data-color='orange'] { --tint: var(--conn-orange); }
.views[data-color='yellow'] { --tint: var(--conn-yellow); }
.views[data-color='green'] { --tint: var(--conn-green); }
.views[data-color='teal'] { --tint: var(--conn-teal); }
.views[data-color='blue'] { --tint: var(--conn-blue); }
.views[data-color='violet'] { --tint: var(--conn-violet); }
.views[data-color='pink'] { --tint: var(--conn-pink); }
.views[data-color] { background: color-mix(in srgb, var(--tint) 14%, var(--s1)); border-bottom-color: color-mix(in srgb, var(--tint) 45%, var(--line)); }
.views a { padding: 9px 12px; color: var(--mut); font-size: 13px; text-decoration: none; border-bottom: 2px solid transparent; }
.views a:hover { color: var(--fg); }
.views a.on { color: var(--acc); border-bottom-color: var(--acc); }
.body { display: flex; flex-wrap: wrap; flex: 1; min-height: 0; }
.content { flex: 999 1 560px; min-width: 0; display: flex; flex-direction: column; }
.error { margin: 24px; padding: 10px 12px; border-radius: var(--radius); background: var(--bad-bg); border: 1px solid var(--bad-line); color: var(--bad); font-size: 13px; }
</style>
