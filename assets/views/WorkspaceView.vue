<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { messageFor } from '../api/errors'
import { ENGINE_BADGES, ENGINE_LABELS } from '../api/types'
import AppHeader from '../components/AppHeader.vue'
import WorkbenchSidebar from '../components/WorkbenchSidebar.vue'
import { useConnectionsStore } from '../stores/connections'
import { useWorkspaceStore, type TableTab } from '../stores/workspace'

const props = defineProps<{ id: string }>()
const route = useRoute()
const router = useRouter()
const connections = useConnectionsStore()
const workspace = useWorkspaceStore()
const error = ref<string | null>(null)
// La page enfant (table, clé, console…) lit l'espace de travail : elle ne se monte qu'une fois la connexion et la base de l'adresse ouvertes.
const ready = ref(false)

const connection = computed(() => connections.byId(props.id))
// Diagramme, audit et activité lisent le serveur SQL : pas de sens sur un moteur clé-valeur. On garde la base et le schéma explorés d'une vue à l'autre.
const hasSchemaViews = computed(() => connection.value !== undefined && connection.value.engine !== 'redis')
const scope = computed(() => ({ ...(workspace.database === null ? {} : { database: workspace.database }), ...(workspace.schema === null ? {} : { schema: workspace.schema }) }))

// Les tables ouvertes forment des onglets : on y revient à la vue où on les a laissées, et ils restent quand on part vers la console,
// le diagramme ou l'audit.
function trackRouteTable(): void {
  if ((route.name !== 'table' && route.name !== 'table-data') || route.params.id !== props.id || typeof route.params.name !== 'string' || !ready.value) {
    return
  }
  const query: Record<string, string> = {}
  for (const key of ['database', 'schema'] as const) {
    const value = route.query[key]
    if (typeof value === 'string' && value !== '') {
      query[key] = value
    }
  }
  workspace.openTableTab(route.params.name, query, route.name === 'table-data' ? 'data' : 'structure')
}
watch(() => [route.name, route.params.id, route.params.name, route.query.database, route.query.schema, ready.value], trackRouteTable, { immediate: true })

const tableTabs = computed(() => workspace.tableTabs)
const tabTarget = (tab: TableTab, view: TableTab['view'] = tab.view) => ({ name: view === 'data' ? 'table-data' : 'table', params: { id: props.id, name: tab.name }, query: tab.query })
const isActive = (tab: TableTab): boolean =>
  (route.name === 'table' || route.name === 'table-data') && route.params.id === props.id && route.params.name === tab.name && (route.query.database ?? '') === (tab.query.database ?? '') && (route.query.schema ?? '') === (tab.query.schema ?? '')
const activeTab = computed(() => tableTabs.value.find(isActive) ?? null)

async function closeTab(tab: TableTab): Promise<void> {
  const wasActive = isActive(tab)
  const index = tableTabs.value.indexOf(tab)
  const remaining = workspace.closeTableTab(tab.key)
  if (!wasActive) {
    return
  }
  const next = remaining[Math.min(index, remaining.length - 1)]
  await router.push(next === undefined ? { name: 'workspace', params: { id: props.id }, query: scope.value } : tabTarget(next))
}

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
    trackRouteTable()
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
      <RouterLink :to="{ name: 'diagram', params: { id }, query: scope }" active-class="on">Diagramme</RouterLink>
      <RouterLink :to="{ name: 'audit', params: { id }, query: scope }" active-class="on">Audit du schéma</RouterLink>
      <RouterLink :to="{ name: 'server-activity', params: { id }, query: scope }" active-class="on">Activité</RouterLink>
    </nav>

    <div v-if="hasSchemaViews && !error && tableTabs.length > 0" class="tabs" :data-color="connection?.color ?? undefined" role="tablist" aria-label="Tables ouvertes">
      <div v-for="tab in tableTabs" :key="tab.key" class="tab" :class="{ on: isActive(tab) }">
        <RouterLink class="label" role="tab" :aria-selected="isActive(tab)" :to="tabTarget(tab)" :title="tab.name">{{ tab.name }}</RouterLink>
        <button type="button" class="close" :aria-label="`Fermer ${tab.name}`" @click="closeTab(tab)">×</button>
      </div>
    </div>
    <nav v-if="activeTab && !error" class="subviews" :aria-label="`Vues de la table ${activeTab.name}`">
      <RouterLink :to="tabTarget(activeTab, 'structure')" :class="{ on: route.name === 'table' }">Structure</RouterLink>
      <RouterLink :to="tabTarget(activeTab, 'data')" :class="{ on: route.name === 'table-data' }">Données</RouterLink>
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
.tabs { display: flex; align-items: flex-end; gap: 4px; padding: 8px 16px 0; background: var(--s2); border-bottom: 1px solid var(--line); overflow-x: auto; }
.tab { display: flex; align-items: center; max-width: 220px; border-radius: 10px 10px 0 0; border: 1px solid transparent; border-bottom: 0; color: var(--mut); }
.tab:hover { background: color-mix(in srgb, var(--fg) 6%, transparent); }
.tab.on { background: var(--bg); border-color: var(--line); color: var(--fg); margin-bottom: -1px; padding-bottom: 1px; }
.tab .label { padding: 8px 4px 8px 14px; color: inherit; text-decoration: none; font-size: 13px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.tab .close { width: 22px; height: 22px; margin-right: 6px; border: 0; border-radius: 11px; background: none; color: var(--mut); cursor: pointer; font-size: 15px; line-height: 1; }
.tab .close:hover { background: var(--s3, var(--line)); color: var(--fg); }
.subviews { display: flex; gap: 4px; padding: 0 28px; border-bottom: 1px solid var(--line); background: var(--bg); }
.subviews a { padding: 9px 12px; color: var(--mut); font-size: 13px; text-decoration: none; border-bottom: 2px solid transparent; }
.subviews a:hover { color: var(--fg); }
.subviews a.on { color: var(--acc); border-bottom-color: var(--acc); }
.body { display: flex; flex-wrap: wrap; flex: 1; min-height: 0; }
.content { flex: 999 1 560px; min-width: 0; display: flex; flex-direction: column; }
.error { margin: 24px; padding: 10px 12px; border-radius: var(--radius); background: var(--bad-bg); border: 1px solid var(--bad-line); color: var(--bad); font-size: 13px; }
</style>
