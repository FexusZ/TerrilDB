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

function changeSchema(event: Event): void {
  const value = (event.target as HTMLSelectElement).value
  void workspace.selectSchema(value === '' ? null : value)
}
</script>

<template>
  <div v-if="workspace.databasesError" class="picker">
    <p class="error" role="alert">{{ workspace.databasesError }}</p>
  </div>
  <template v-else>
    <div v-if="workspace.databases.length > 1" class="picker">
      <label for="database">Base</label>
      <select id="database" class="databases" :value="workspace.database ?? ''" @change="change">
        <option v-if="workspace.defaultDatabase !== null" value="">Base par défaut</option>
        <option v-for="database in workspace.databases" :key="database.name" :value="database.name">{{ database.name }}</option>
      </select>
    </div>
    <div v-if="workspace.schemas.length > 1" class="picker">
      <label for="schema">Schéma</label>
      <select id="schema" class="schemas" :value="workspace.schema ?? ''" @change="changeSchema">
        <option value="">Schéma par défaut</option>
        <option v-for="schema in workspace.schemas" :key="schema.name" :value="schema.name">{{ schema.name }}</option>
      </select>
    </div>
  </template>
</template>

<style scoped>
.picker { display: flex; flex-direction: column; gap: 6px; padding: 10px 12px 0; font-size: 12px; color: var(--mut); }
select { height: 32px; padding: 0 8px; border-radius: var(--radius); border: 1px solid var(--line); background: var(--s2); color: var(--fg); font: 400 13px var(--font-ui); }
.error { padding: 8px 10px; border-radius: var(--radius); background: var(--bad-bg); border: 1px solid var(--bad-line); color: var(--bad); }
</style>
