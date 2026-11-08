<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { messageFor } from '../api/errors'
import { ENGINE_LABELS } from '../api/types'
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
          <p>Choisissez une connexion ci-dessous ou dans la barre de droite : un clic l’ouvre, un second la referme. Ouvrez une base pour voir ses tables, puis une table pour lire sa structure.</p>
          <ul class="cards" aria-label="Vos connexions">
            <li v-for="connection in connections.items" :key="connection.id" class="card" :data-color="connection.color ?? undefined">
              <span class="engine">{{ ENGINE_LABELS[connection.engine] }}</span>
              <h2>{{ connection.name }}</h2>
              <div class="row">
                <RouterLink class="open" :to="{ name: 'workspace', params: { id: connection.id } }">Ouvrir la console</RouterLink>
                <RouterLink class="edit" :to="{ name: 'connection-edit', params: { id: connection.id } }">Modifier</RouterLink>
              </div>
            </li>
          </ul>
          <RouterLink class="new-connection" :to="{ name: 'connection-new' }">+ Nouvelle connexion</RouterLink>
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
.content > p { max-width: 680px; }
h1 { font-size: 30px; line-height: 1.1; font-weight: 600; }
p { color: var(--mut); }
.cards { list-style: none; margin: 8px 0 0; padding: 0; display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 14px; }
.card { position: relative; display: flex; flex-direction: column; gap: 6px; padding: 16px 18px; border-radius: var(--radius-lg); border: 1px solid var(--line); background: var(--s1); }
.card[data-color] { border-left: 4px solid var(--tint); }
.card[data-color='red'] { --tint: var(--conn-red); }
.card[data-color='orange'] { --tint: var(--conn-orange); }
.card[data-color='yellow'] { --tint: var(--conn-yellow); }
.card[data-color='green'] { --tint: var(--conn-green); }
.card[data-color='teal'] { --tint: var(--conn-teal); }
.card[data-color='blue'] { --tint: var(--conn-blue); }
.card[data-color='violet'] { --tint: var(--conn-violet); }
.card[data-color='pink'] { --tint: var(--conn-pink); }
.card[data-color] { background: color-mix(in srgb, var(--tint) 8%, var(--s1)); }
.engine { font: 500 11px var(--font-mono); color: var(--mut); text-transform: uppercase; letter-spacing: 0.06em; }
h2 { font: 600 22px/1.2 var(--font-display); }
.row { display: flex; gap: 8px; margin-top: 8px; flex-wrap: wrap; }
.open, .edit, .new-connection { display: inline-flex; align-items: center; height: 30px; padding: 0 14px; border-radius: 15px; border: 1px solid var(--line-strong); color: var(--fg); text-decoration: none; font-size: 13px; }
.open { background: var(--acc); border-color: var(--acc); color: var(--on-acc, #fff); }
.new-connection { align-self: flex-start; margin-top: 4px; border-style: dashed; color: var(--acc); }
.add-first { align-self: flex-start; padding: 8px 14px; border-radius: var(--radius); background: var(--acc); color: var(--on-acc, #fff); text-decoration: none; }
.error { padding: 10px 12px; border-radius: var(--radius); background: var(--bad-bg); border: 1px solid var(--bad-line); color: var(--bad); font-size: 13px; }
</style>
