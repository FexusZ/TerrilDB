<script setup lang="ts">
import type { Cell, ColumnInfo, QueryResult } from '../api/types'
import type { DataDraft, Typed } from '../utils/dataDraft'

const props = defineProps<{ result: QueryResult; columns: ColumnInfo[]; draft: DataDraft }>()
const emit = defineEmits<{
  edit: [row: number, column: string, value: Typed]
  toggleDelete: [row: number]
  editInsert: [index: number, column: string, value: Typed]
  removeInsert: [index: number]
  addRow: []
}>()

const nullable = (name: string): boolean => props.columns.find((column) => column.name === name)?.nullable ?? false
const typeOf = (name: string): string => props.columns.find((column) => column.name === name)?.type ?? ''

/** La valeur montrée : celle qu'on a saisie si la cellule est modifiée, sinon celle de la base. */
function current(row: number, position: number, name: string): Cell {
  const edited = props.draft.edits[row]
  if (edited !== undefined && name in edited) {
    return edited[name] ?? null
  }

  return props.result.rows[row]?.[position] ?? null
}

const text = (value: Cell): string => (value === null ? '' : String(value))
const changed = (row: number, name: string): boolean => props.draft.edits[row] !== undefined && name in (props.draft.edits[row] ?? {})
const inputValue = (event: Event): string => (event.target as HTMLInputElement).value
</script>

<template>
  <div class="scroll">
    <table class="grid">
      <thead>
        <tr>
          <th v-for="name in result.columns" :key="name" scope="col">{{ name }}<small>{{ typeOf(name) }}</small></th>
          <th class="act" scope="col"><span class="sr-only">Actions</span></th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="(row, index) in result.rows" :key="index" :class="{ deleted: draft.deleted.has(index) }">
          <td v-for="(name, position) in result.columns" :key="name" :class="{ changed: changed(index, name), null: current(index, position, name) === null }">
            <input
              class="field"
              type="text"
              :value="text(current(index, position, name))"
              :placeholder="current(index, position, name) === null ? 'NULL' : ''"
              :disabled="draft.deleted.has(index)"
              :aria-label="`${name}, ligne ${index + 1}`"
              @input="emit('edit', index, name, inputValue($event))"
            >
            <button v-if="nullable(name) && current(index, position, name) !== null && !draft.deleted.has(index)" type="button" class="set-null" :aria-label="`Mettre ${name} à NULL, ligne ${index + 1}`" title="Mettre à NULL" @click="emit('edit', index, name, null)">∅</button>
          </td>
          <td class="act">
            <button type="button" class="row-delete" :aria-pressed="draft.deleted.has(index)" :aria-label="draft.deleted.has(index) ? `Garder la ligne ${index + 1}` : `Supprimer la ligne ${index + 1}`" @click="emit('toggleDelete', index)">{{ draft.deleted.has(index) ? '↶' : '✕' }}</button>
          </td>
        </tr>
        <tr v-for="(row, index) in draft.inserts" :key="`new-${index}`" class="new-row">
          <td v-for="name in result.columns" :key="name" :class="{ null: row[name] === null }">
            <input class="field" type="text" :value="row[name] ?? ''" :placeholder="row[name] === null ? 'NULL' : 'défaut'" :aria-label="`${name}, nouvelle ligne ${index + 1}`" @input="emit('editInsert', index, name, inputValue($event))">
            <button v-if="nullable(name) && row[name] !== null" type="button" class="set-null" :aria-label="`Mettre ${name} à NULL, nouvelle ligne ${index + 1}`" title="Mettre à NULL" @click="emit('editInsert', index, name, null)">∅</button>
          </td>
          <td class="act"><button type="button" class="row-delete" :aria-label="`Retirer la nouvelle ligne ${index + 1}`" @click="emit('removeInsert', index)">✕</button></td>
        </tr>
      </tbody>
    </table>
    <p v-if="result.rowCount === 0 && draft.inserts.length === 0" class="empty">Aucune ligne.</p>
    <div class="add"><button type="button" class="add-row" @click="emit('addRow')">+ Ajouter une ligne</button></div>
  </div>
</template>

<style scoped>
.scroll { overflow: auto; }
.grid { border-collapse: collapse; width: 100%; font-size: 13px; }
th { position: sticky; top: 0; z-index: 1; padding: 8px 10px; text-align: left; font: 600 12px var(--font-ui); color: var(--mut); background: var(--s1); border-bottom: 1px solid var(--line); white-space: nowrap; }
th small { margin-left: 6px; font: 400 11px var(--font-mono); opacity: 0.8; }
td { position: relative; padding: 3px 6px; border-bottom: 1px solid var(--line); }
tbody tr:hover td { background: color-mix(in srgb, var(--acc) 5%, transparent); }
.field { width: 100%; min-width: 90px; height: 28px; padding: 0 8px; border-radius: var(--radius); border: 1px solid var(--line); background: var(--s2); color: var(--fg); font: 400 13px var(--font-mono); }
.field:hover:not(:disabled) { border-color: var(--acc-line); }
.field:focus { outline: 2px solid var(--acc-bg); border-color: var(--acc); }
.field::placeholder { color: var(--mut); font-style: italic; }
td.changed .field { border-color: var(--acc); background: var(--acc-bg); }
td.changed::after { content: ''; position: absolute; left: 0; top: 4px; bottom: 4px; width: 3px; border-radius: 2px; background: var(--acc); }
tr.deleted .field { text-decoration: line-through; opacity: 0.55; background: var(--bad-bg); border-color: var(--bad-line); }
tr.new-row td { background: var(--ok-bg, var(--acc-bg)); }
.set-null { position: absolute; right: 10px; top: 50%; transform: translateY(-50%); display: none; width: 20px; height: 20px; border: 0; border-radius: 10px; background: var(--s1); color: var(--mut); cursor: pointer; font-size: 12px; }
td:hover .set-null, td:focus-within .set-null { display: block; }
.act { width: 44px; text-align: center; }
.row-delete { width: 26px; height: 26px; border: 0; border-radius: 13px; background: none; color: var(--mut); cursor: pointer; opacity: 0.75; }
tr:hover .row-delete { opacity: 1; color: var(--bad); }
.add { padding: 10px 6px; }
.add-row { height: 30px; padding: 0 14px; border-radius: 15px; border: 1px dashed var(--acc-line); background: none; color: var(--acc); font: 500 13px var(--font-ui); cursor: pointer; }
.add-row:hover { background: var(--acc-bg); }
.empty { padding: 16px 10px; color: var(--mut); }
</style>
