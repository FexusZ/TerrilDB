<script setup lang="ts">
import { ref } from 'vue'
import type { KeyEntry } from '../api/types'
import { formatTtl } from '../utils/format'

defineProps<{ connectionId: string; keys: KeyEntry[]; total: number; hasMore: boolean; loading: boolean; current?: string; error?: string | null }>()
const emit = defineEmits<{ search: [pattern: string]; more: [] }>()

const pattern = ref('')
const number = new Intl.NumberFormat('fr-FR')

function search(): void {
  emit('search', pattern.value.trim() === '' ? '*' : pattern.value.trim())
}
</script>

<template>
  <nav class="tree" aria-label="Clés">
    <form class="search" @submit.prevent="search">
      <label class="sr-only" for="pattern">Motif de recherche</label>
      <input id="pattern" v-model="pattern" type="search" placeholder="Motif (ex. user:*)" autocomplete="off" spellcheck="false">
    </form>

    <p class="total">{{ number.format(total) }} clés dans la base</p>

    <p v-if="error" class="error" role="alert">{{ error }}</p>
    <p v-else-if="keys.length === 0 && !loading" class="note">Aucune clé ne correspond.</p>

    <RouterLink
      v-for="key in keys"
      :key="key.name"
      class="node"
      :class="{ cur: key.name === current }"
      :aria-current="key.name === current ? 'page' : undefined"
      :to="{ name: 'key', params: { id: connectionId }, query: { name: key.name } }"
    >
      <span class="label"><span class="type">{{ key.type }}</span><span class="name">{{ key.name }}</span></span>
      <small v-if="key.ttl !== null">{{ formatTtl(key.ttl) }}</small>
    </RouterLink>

    <button v-if="hasMore" type="button" class="more" :disabled="loading" @click="emit('more')">{{ loading ? 'Chargement…' : 'Charger la suite' }}</button>
    <p class="note">Le parcours se fait par tranches (SCAN) : une tranche peut ne rien ramener alors qu’il en reste.</p>
  </nav>
</template>

<style scoped>
.tree { display: flex; flex-direction: column; gap: 2px; padding: 14px 8px; }
.search { padding: 0 4px 8px; }
input { width: 100%; height: 34px; padding: 0 10px; border-radius: var(--radius); border: 1px solid var(--line); background: var(--s1); color: var(--fg); font: 400 13px var(--font-mono); }
.total { padding: 0 12px 8px; font: 500 11px var(--font-mono); letter-spacing: 0.04em; color: var(--mut); }
.node { display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 6px 12px; border-radius: 5px; color: var(--fg); text-decoration: none; font-size: 13px; }
.node:hover { background: var(--s2); }
.node.cur { background: var(--acc-bg); color: var(--acc); }
.label { display: flex; align-items: center; gap: 8px; min-width: 0; }
.type { flex: none; padding: 1px 6px; border-radius: 4px; border: 1px solid var(--line); font: 500 10px var(--font-mono); color: var(--mut); }
.name { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-family: var(--font-mono); }
small { flex: none; font: 400 11px var(--font-mono); color: var(--mut); }
.more { margin: 8px 4px 0; height: 34px; border-radius: var(--radius); border: 1px solid var(--line); background: var(--s2); color: var(--acc); font: 500 13px var(--font-ui); cursor: pointer; }
.more:disabled { opacity: 0.6; cursor: default; }
.note { padding: 14px 12px 0; font-size: 11px; color: var(--mut); }
.error { margin: 4px; padding: 10px 12px; border-radius: var(--radius); background: var(--bad-bg); border: 1px solid var(--bad-line); color: var(--bad); font-size: 13px; }
</style>
