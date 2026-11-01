<script setup lang="ts">
import { ref, watch } from 'vue'
import { api } from '../api/client'
import { messageFor } from '../api/errors'
import type { KeyDetail } from '../api/types'
import { formatTtl } from '../utils/format'

const props = defineProps<{ id: string; name: string }>()

const key = ref<KeyDetail | null>(null)
const error = ref<string | null>(null)
const number = new Intl.NumberFormat('fr-FR')
let latest = 0

function display(cell: string | number | boolean | null): string {
  if (cell === null) {
    return 'NULL'
  }

  return typeof cell === 'number' ? number.format(cell) : String(cell)
}

watch(
  () => props.name,
  async (name) => {
    const request = ++latest
    key.value = null
    error.value = null
    try {
      const detail = await api<{ key: KeyDetail }>('GET', `/web/connections/${props.id}/key?${new URLSearchParams({ name }).toString()}`)
      if (request === latest) {
        key.value = detail.key
      }
    } catch (failure) {
      if (request === latest) {
        error.value = messageFor(failure)
      }
    }
  },
  { immediate: true },
)
</script>

<template>
  <section class="pane">
    <p v-if="error" class="error" role="alert">{{ error }}</p>
    <template v-else-if="key">
      <div class="head">
        <h1>{{ key.name }}</h1>
        <span class="chip">{{ key.type }}</span>
        <span v-if="key.size !== null" class="chip">{{ number.format(key.size) }} {{ key.size > 1 ? 'éléments' : 'élément' }}</span>
        <span class="chip">{{ key.ttl === null ? formatTtl(null) : `expire dans ${formatTtl(key.ttl)}` }}</span>
      </div>
      <p v-if="key.truncated" class="truncated" role="status">Seules les {{ number.format(key.rows.length) }} premières lignes sont affichées{{ key.size !== null ? ` sur ${number.format(key.size)}` : '' }}.</p>
      <div class="scroll">
        <table>
          <thead><tr><th v-for="column in key.columns" :key="column" scope="col">{{ column }}</th></tr></thead>
          <tbody>
            <tr v-for="(row, index) in key.rows" :key="index">
              <td v-for="(cell, position) in row" :key="position" :class="{ null: cell === null, num: typeof cell === 'number' }">{{ display(cell) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>
    <p v-else class="loading" role="status">Chargement…</p>
  </section>
</template>

<style scoped>
.pane { min-width: 0; overflow: auto; flex: 1; }
.head { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; padding: 22px 28px 14px; border-bottom: 1px solid var(--line); }
h1 { font: 600 20px var(--font-mono); letter-spacing: -0.01em; overflow-wrap: anywhere; }
.chip { display: inline-flex; align-items: center; height: 24px; padding: 0 10px; border-radius: 12px; border: 1px solid var(--line); font-size: 12px; color: var(--mut); }
.truncated { margin: 14px 28px 0; padding: 8px 12px; border-radius: var(--radius); background: var(--s2); border: 1px solid var(--line); font-size: 12px; color: var(--mut); }
.scroll { overflow: auto; padding: 16px 28px 48px; }
table { border-collapse: collapse; font: 400 13px var(--font-mono); }
th { text-align: left; padding: 8px 14px; border-bottom: 1px solid var(--line-strong); color: var(--mut); font-weight: 500; }
td { padding: 7px 14px; border-bottom: 1px solid var(--line-soft); white-space: pre-wrap; overflow-wrap: anywhere; vertical-align: top; }
td.null { color: var(--mut); font-style: italic; }
td.num { text-align: right; }
.loading { padding: 24px 28px; color: var(--mut); }
.error { margin: 24px 28px; padding: 10px 12px; border-radius: var(--radius); background: var(--bad-bg); border: 1px solid var(--bad-line); color: var(--bad); font-size: 13px; }
</style>
