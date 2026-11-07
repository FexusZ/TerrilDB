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

    <nav v-if="hasSchemaViews && !error" class="views" aria-label="Vues de la connexion">
      <RouterLink :to="{ name: 'workspace', params: { id }, query: scope }" exact-active-class="on">Console</RouterLink>
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
.views a { padding: 9px 12px; color: var(--mut); font-size: 13px; text-decoration: none; border-bottom: 2px solid transparent; }
.views a:hover { color: var(--fg); }
.views a.on { color: var(--acc); border-bottom-color: var(--acc); }
.body { display: flex; flex-wrap: wrap; flex: 1; min-height: 0; }
.content { flex: 999 1 560px; min-width: 0; display: flex; flex-direction: column; }
.error { margin: 24px; padding: 10px 12px; border-radius: var(--radius); background: var(--bad-bg); border: 1px solid var(--bad-line); color: var(--bad); font-size: 13px; }
</style>
