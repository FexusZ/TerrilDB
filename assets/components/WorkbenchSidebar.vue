<script setup lang="ts">
import { ref } from 'vue'
import ConnectionTree from './ConnectionTree.vue'

const emit = defineEmits<{ deleted: [id: string] }>()

const KEY = 'terrildb.sidebar'

function read(): boolean {
  try {
    return localStorage.getItem(KEY) === 'folded'
  } catch {
    // Stockage refusé (navigation privée…) : la barre reste simplement dépliée.
    return false
  }
}

const folded = ref(read())

function toggle(): void {
  folded.value = !folded.value
  try {
    localStorage.setItem(KEY, folded.value ? 'folded' : 'open')
  } catch {
    // Le choix vaut pour cette page seulement.
  }
}
</script>

<template>
  <nav class="workbench" :class="{ folded }" aria-label="Barre des connexions">
    <div class="head">
      <h2 v-if="!folded">Connexions</h2>
      <button type="button" class="fold" :aria-expanded="!folded" :aria-label="folded ? 'Afficher les connexions' : 'Masquer les connexions'" @click="toggle">{{ folded ? '»' : '«' }}</button>
    </div>
    <template v-if="!folded">
      <ConnectionTree @deleted="emit('deleted', $event)" />
      <div class="foot">
        <RouterLink class="add" :to="{ name: 'connection-new' }">+ Nouvelle connexion</RouterLink>
        <RouterLink class="manage" :to="{ name: 'connections' }">Gérer les connexions</RouterLink>
      </div>
    </template>
  </nav>
</template>

<style scoped>
.workbench { flex: 0 0 260px; display: flex; flex-direction: column; gap: 4px; padding: 10px 6px; background: var(--s1); border-right: 1px solid var(--line); overflow-y: auto; }
.workbench.folded { flex-basis: 44px; align-items: center; }
.head { display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 0 6px; }
h2 { font: 500 11px var(--font-mono); letter-spacing: 0.08em; text-transform: uppercase; color: var(--mut); }
.fold { width: 26px; height: 26px; border: 1px solid var(--line); border-radius: 5px; background: var(--s2); color: var(--fg); cursor: pointer; }
.foot { display: flex; flex-direction: column; gap: 4px; margin-top: auto; padding: 10px 6px 0; border-top: 1px solid var(--line); font-size: 13px; }
.add { color: var(--acc); text-decoration: none; }
.manage { color: var(--mut); text-decoration: none; }
@media (max-width: 760px) { .workbench { flex-basis: 100%; border-right: 0; border-bottom: 1px solid var(--line); max-height: 40vh; } }
</style>
