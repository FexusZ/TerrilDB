<script setup lang="ts">
import { computed } from 'vue'
import type { Cell, QueryResult } from '../api/types'
import { formatDuration } from '../utils/format'

const props = defineProps<{ result: QueryResult }>()
const number = new Intl.NumberFormat('fr-FR')

function display(cell: Cell): string {
  if (cell === null) {
    return 'NULL'
  }
  if (typeof cell === 'number') {
    return number.format(cell)
  }

  return String(cell)
}

const numeric = (cell: Cell): boolean => typeof cell === 'number'
const plural = (count: number, one: string, many: string): string => `${number.format(count)} ${count > 1 ? many : one}`
const affected = computed(() => props.result.affectedRows)
</script>

<template>
  <div class="result">
    <div class="summary">
      <template v-if="affected !== null">
        <span class="count">{{ plural(affected, 'ligne modifiée', 'lignes modifiées') }}</span>
      </template>
      <template v-else>
        <span class="count">{{ plural(result.rowCount, 'ligne', 'lignes') }}</span>
      </template>
      <span class="time">{{ formatDuration(result.durationMs) }}</span>
    </div>

    <p v-if="result.truncated" class="truncated" role="status">Résultat limité à {{ number.format(result.rowCount) }} lignes : d’autres existent. Affinez la requête ou ajoutez un LIMIT.</p>

    <div v-if="affected === null" class="scroll">
      <table>
        <thead>
          <tr>
            <th v-for="column in result.columns" :key="column" scope="col">{{ column }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(row, index) in result.rows" :key="index">
            <td v-for="(cell, position) in row" :key="position" :class="{ num: numeric(cell), null: cell === null }" :title="typeof cell === 'string' && cell.length > 60 ? cell : undefined">{{ display(cell) }}</td>
          </tr>
        </tbody>
      </table>
      <p v-if="result.rowCount === 0" class="empty">Aucune ligne.</p>
    </div>
  </div>
</template>

<style scoped>
.result { display: flex; flex-direction: column; min-height: 0; }
.summary { display: flex; gap: 12px; align-items: center; padding: 8px 16px; background: var(--s1); border-bottom: 1px solid var(--line); font-size: 13px; }
.count { color: var(--ok); font-weight: 600; }
.time { color: var(--mut); }
.truncated { padding: 8px 16px; background: var(--sec-bg); border-bottom: 1px solid var(--sec-line); color: var(--sec-ink); font-size: 13px; }
.scroll { overflow: auto; min-height: 120px; }
table { border-collapse: collapse; width: 100%; font: 400 13px var(--font-mono); }
th { position: sticky; top: 0; text-align: left; font-weight: 500; color: var(--mut); background: var(--s2); padding: 8px 14px; border-bottom: 1px solid var(--line); white-space: nowrap; }
td { padding: 7px 14px; border-bottom: 1px solid var(--line-soft); white-space: nowrap; max-width: 420px; overflow: hidden; text-overflow: ellipsis; }
td.num { text-align: right; font-variant-numeric: tabular-nums; }
td.null { color: var(--mut); font-style: italic; }
.empty { padding: 24px 16px; color: var(--mut); }
</style>
