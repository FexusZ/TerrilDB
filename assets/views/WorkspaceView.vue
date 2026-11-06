<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { messageFor } from '../api/errors'
import { ENGINE_BADGES, ENGINE_LABELS } from '../api/types'
import AppHeader from '../components/AppHeader.vue'
import KeyBrowser from '../components/KeyBrowser.vue'
import SchemaTree from '../components/SchemaTree.vue'
import WorkbenchSidebar from '../components/WorkbenchSidebar.vue'
import { useConnectionsStore } from '../stores/connections'
import { useWorkspaceStore } from '../stores/workspace'

const props = defineProps<{ id: string }>()
const route = useRoute()
const router = useRouter()
const connections = useConnectionsStore()
const workspace = useWorkspaceStore()
const error = ref<string | null>(null)

const connection = computed(() => connections.byId(props.id))
const currentTable = computed(() => (typeof route.params.name === 'string' ? route.params.name : undefined))
const currentKey = computed(() => (typeof route.query.name === 'string' ? route.query.name : undefined))

async function open(): Promise<void> {
  error.value = null
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
        <RouterLink v-if="connection" :to="{ name: 'workspace', params: { id } }" class="current">
          <span class="engine" aria-hidden="true">{{ ENGINE_BADGES[connection.engine] }}</span>
          <span class="name">{{ connection.name }}</span>
          <span class="sr-only">{{ ENGINE_LABELS[connection.engine] }}</span>
        </RouterLink>
        <RouterLink :to="{ name: 'connections' }" class="switch">Changer de connexion</RouterLink>
      </template>
    </AppHeader>

    <p v-if="error" class="error" role="alert">{{ error }}</p>
    <div v-else class="body">
      <WorkbenchSidebar @deleted="onDeleted" />
      <aside class="side">
        <KeyBrowser
          v-if="workspace.isKeyValue"
          :connection-id="id"
          :keys="workspace.keys"
          :total="workspace.keysTotal"
          :has-more="workspace.keysCursor !== null"
          :loading="workspace.keysLoading"
          :current="currentKey"
          :error="workspace.keysError"
          @search="workspace.searchKeys"
          @more="workspace.loadMoreKeys"
        />
        <template v-else>
          <SchemaTree
            :connection-id="id"
            :tables="workspace.tables"
            :total="workspace.tablesTotal"
            :has-more="workspace.tablesHasMore"
            :loading="workspace.tablesLoading"
            :search="workspace.tablesSearch"
            :current="currentTable"
            :error="workspace.tablesError"
            @search="workspace.searchTables"
            @more="workspace.loadMoreTables"
          />
        </template>
      </aside>
      <div class="content"><RouterView /></div>
    </div>
  </div>
</template>

<style scoped>
.shell { display: flex; flex-direction: column; min-height: 100vh; }
.current { display: inline-flex; align-items: center; gap: 10px; padding: 0 12px; height: 34px; border-radius: var(--radius); border: 1px solid var(--line); background: var(--s2); color: var(--fg); text-decoration: none; font-weight: 500; }
.engine { font: 500 11px var(--font-mono); color: var(--acc); }
.switch { color: var(--mut); font-size: 13px; }
.body { display: flex; flex-wrap: wrap; flex: 1; min-height: 0; }
.side { flex: 1 1 280px; max-width: 300px; background: var(--s1); border-right: 1px solid var(--line); }
.content { flex: 999 1 560px; min-width: 0; display: flex; flex-direction: column; }
.error { margin: 24px; padding: 10px 12px; border-radius: var(--radius); background: var(--bad-bg); border: 1px solid var(--bad-line); color: var(--bad); font-size: 13px; }
@media (max-width: 760px) { .side { max-width: none; border-right: 0; border-bottom: 1px solid var(--line); } }
</style>
