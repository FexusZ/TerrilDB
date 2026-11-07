<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { messageFor } from '../api/errors'
import AppHeader from '../components/AppHeader.vue'
import WorkbenchSidebar from '../components/WorkbenchSidebar.vue'
import { useConnectionsStore } from '../stores/connections'

const connections = useConnectionsStore()
const error = ref<string | null>(null)

onMounted(async () => {
  if (connections.loaded) {
    return
  }
  try {
    await connections.load()
  } catch (failure) {
    error.value = messageFor(failure)
  }
})
</script>

<template>
  <div class="shell">
    <AppHeader />
    <div class="body">
      <main class="content">
        <p v-if="error" class="error" role="alert">{{ error }}</p>
        <template v-else-if="connections.loaded && connections.items.length === 0">
          <h1>Bienvenue</h1>
          <p>Ajoutez une première base pour l’explorer et l’interroger.</p>
          <RouterLink class="add-first" :to="{ name: 'connection-new' }">Ajouter une base</RouterLink>
        </template>
        <template v-else>
          <h1>Votre espace de travail</h1>
          <p>Choisissez une connexion dans la barre de droite : un clic l’ouvre, un second la referme. Ouvrez une base pour voir ses tables, puis une table pour lire sa structure.</p>
        </template>
      </main>
      <WorkbenchSidebar />
    </div>
  </div>
</template>

<style scoped>
.shell { display: flex; flex-direction: column; min-height: 100vh; }
.body { display: flex; flex-wrap: wrap; flex: 1; min-height: 0; }
.content { flex: 999 1 560px; min-width: 0; display: flex; flex-direction: column; gap: 10px; padding: 36px 32px; }
.content > * { max-width: 680px; }
h1 { font-size: 24px; font-weight: 700; }
p { color: var(--mut); }
.add-first { align-self: flex-start; padding: 8px 14px; border-radius: var(--radius); background: var(--acc); color: var(--on-acc, #fff); text-decoration: none; }
.error { padding: 10px 12px; border-radius: var(--radius); background: var(--bad-bg); border: 1px solid var(--bad-line); color: var(--bad); font-size: 13px; }
</style>
