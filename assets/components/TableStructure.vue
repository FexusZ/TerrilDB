<script setup lang="ts">
import { computed } from 'vue'
import type { ForeignKeyInfo, TableDetail } from '../api/types'

const props = defineProps<{ connectionId: string; table: TableDetail }>()

const primary = computed(() => new Set(props.table.indexes.filter((index) => index.primary).flatMap((index) => index.columns)))
const foreign = computed(() => new Set(props.table.foreignKeys.flatMap((key) => key.columns)))

const ACTIONS: Record<string, string> = {
  no_action: 'aucune action',
  restrict: 'blocage',
  cascade: 'en cascade',
  set_null: 'mise à NULL',
  set_default: 'valeur par défaut',
}
const action = (value: string): string => ACTIONS[value] ?? value
const indexKind = (primaryKey: boolean, unique: boolean): string => (primaryKey ? 'clé primaire' : unique ? 'unique' : 'simple')
const target = (key: ForeignKeyInfo): string => `${key.referencedTable} (${key.referencedColumns.join(', ')})`
</script>

<template>
  <div class="structure">
    <section v-if="table.viewDefinition" class="definition">
      <h2>Définition</h2>
      <pre>{{ table.viewDefinition }}</pre>
    </section>

    <section class="columns">
      <h2>Colonnes</h2>
      <div class="box">
        <table>
          <thead><tr><th>Nom</th><th>Type</th><th>Null</th><th>Défaut</th><th>Notes</th></tr></thead>
          <tbody>
            <tr v-for="column in table.columns" :key="column.name">
              <td class="m">{{ column.name }}</td>
              <td class="m">{{ column.type }}</td>
              <td>{{ column.nullable ? 'oui' : 'non' }}</td>
              <td class="m dim">{{ column.autoIncrement ? 'auto-incrément' : (column.default ?? 'aucun') }}</td>
              <td>
                <span v-if="primary.has(column.name)" class="tag pk">PK</span>
                <span v-if="foreign.has(column.name)" class="tag">FK</span>
                <span v-if="column.comment" class="comment">{{ column.comment }}</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <section v-if="table.kind === 'table'" class="indexes">
      <h2>Index</h2>
      <p v-if="table.indexes.length === 0" class="none">Aucun index.</p>
      <div v-else class="box">
        <table>
          <thead><tr><th>Nom</th><th>Colonnes</th><th>Type</th></tr></thead>
          <tbody>
            <tr v-for="index in table.indexes" :key="index.name">
              <td class="m">{{ index.name }}</td>
              <td class="m">{{ index.columns.join(', ') }}</td>
              <td><span class="tag" :class="{ pk: index.primary, u: index.unique && !index.primary }">{{ indexKind(index.primary, index.unique) }}</span></td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <section v-if="table.kind === 'table'" class="foreign-keys">
      <h2>Clés étrangères</h2>
      <p v-if="table.foreignKeys.length === 0" class="none">Aucune clé étrangère.</p>
      <div v-else class="box">
        <table>
          <thead><tr><th>Nom</th><th>Colonnes</th><th>Référence</th><th>À la suppression</th><th>À la mise à jour</th></tr></thead>
          <tbody>
            <tr v-for="key in table.foreignKeys" :key="key.name">
              <td class="m">{{ key.name }}</td>
              <td class="m">{{ key.columns.join(', ') }}</td>
              <td class="m"><RouterLink :to="{ name: 'table', params: { id: connectionId, name: key.referencedTable } }">{{ target(key) }}</RouterLink></td>
              <td>suppression {{ action(key.onDelete) }}</td>
              <td>mise à jour {{ action(key.onUpdate) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  </div>
</template>

<style scoped>
.structure { display: flex; flex-direction: column; gap: 28px; min-width: 0; }
section { display: flex; flex-direction: column; gap: 10px; }
h2 { font-size: 15px; font-weight: 600; }
.box { border: 1px solid var(--line); border-radius: 8px; overflow-x: auto; background: var(--s1); }
table { border-collapse: collapse; width: 100%; font-size: 13px; }
th { text-align: left; font: 500 11px var(--font-mono); letter-spacing: 0.06em; text-transform: uppercase; color: var(--mut); padding: 9px 16px; background: var(--s2); border-bottom: 1px solid var(--line); white-space: nowrap; }
td { padding: 10px 16px; border-bottom: 1px solid #1f2b30; white-space: nowrap; }
tr:last-child td { border-bottom: 0; }
.m { font-family: var(--font-mono); font-size: 12.5px; }
.dim { color: var(--mut); }
.tag { display: inline-flex; align-items: center; height: 20px; padding: 0 8px; border-radius: 4px; font: 500 11px var(--font-mono); border: 1px solid var(--line); color: var(--mut); margin-right: 6px; }
.tag.pk { color: var(--sec); border-color: var(--sec-line); background: var(--sec-bg); }
.tag.u { color: var(--acc); border-color: var(--acc-line); background: var(--acc-bg); }
.comment { color: var(--mut); font-size: 12px; }
.none { color: var(--mut); }
.definition pre { margin: 0; padding: 14px 16px; border: 1px solid var(--line); border-radius: 8px; background: var(--s1); font: 400 12.5px/1.6 var(--font-mono); overflow-x: auto; white-space: pre-wrap; }
</style>
