<script setup lang="ts">
import { watch } from 'vue'
import { useWorkspaceStore } from '../stores/workspace'

const workspace = useWorkspaceStore()

// La connexion n'est ouverte qu'après le montage de la barre latérale : on suit le changement de connexion.
watch(() => workspace.connectionId, () => workspace.loadDatabases(), { immediate: true })

function change(event: Event): void {
  const value = (event.target as HTMLSelectElement).value
  void workspace.selectDatabase(value === '' ? null : value)
}
</script>

<template>
  <div v-if="workspace.databasesError" class="picker">
    <p class="error" role="alert">{{ workspace.databasesError }}</p>
  </div>
  <div v-else-if="workspace.databases.length > 1" class="picker">
    <label for="database">Base</label>
    <select id="database" class="databases" :value="workspace.database ?? ''" @change="change">
      <option value="">Base par défaut</option>
      <option v-for="database in workspace.databases" :key="database.name" :value="database.name">{{ database.name }}</option>
    </select>
  </div>
</template>

<style scoped>
.picker { display: flex; flex-direction: column; gap: 6px; padding: 10px 12px 0; font-size: 12px; color: var(--mut); }
select { height: 32px; padding: 0 8px; border-radius: var(--radius); border: 1px solid var(--line); background: var(--s2); color: var(--fg); font: 400 13px var(--font-ui); }
.error { padding: 8px 10px; border-radius: var(--radius); background: var(--bad-bg); border: 1px solid var(--bad-line); color: var(--bad); }
</style>
