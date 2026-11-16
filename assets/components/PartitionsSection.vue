<script setup lang="ts">
import { computed } from 'vue'
import type { PartitioningInfo } from '../api/types'
import { formatBytes, formatEstimate } from '../utils/format'

const props = defineProps<{ partitioning: PartitioningInfo }>()

const count = computed(() => props.partitioning.partitions.length)
const hasBounds = computed(() => props.partitioning.partitions.some((partition) => partition.bound !== null))
const method = (value: string): string => value.toUpperCase()
</script>

<template>
  <section class="partitions">
    <h2>Partitions</h2>
    <p class="summary">
      Méthode <strong>{{ method(partitioning.method) }}</strong> sur <code>{{ partitioning.expression }}</code>, {{ count }} {{ count > 1 ? 'partitions' : 'partition' }}<template v-if="partitioning.subMethod">,
        sous-partitions {{ method(partitioning.subMethod) }} sur <code>{{ partitioning.subExpression }}</code></template>.
      <span class="dim">En lecture seule : les partitions ne se modifient pas depuis cet écran.</span>
    </p>
    <div class="box">
      <table>
        <thead>
          <tr><th>Nom</th><th v-if="hasBounds">Borne</th><th>Lignes</th><th>Taille</th></tr>
        </thead>
        <tbody>
          <template v-for="partition in partitioning.partitions" :key="partition.name">
            <tr>
              <td class="m">
                {{ partition.name }}
                <span v-if="partition.catchAll" class="tag">fourre-tout</span>
              </td>
              <td v-if="hasBounds" class="m dim">{{ partition.bound ?? '' }}</td>
              <td class="m">{{ formatEstimate(partition.estimatedRows) || '—' }}</td>
              <td class="m">{{ formatBytes(partition.dataBytes) }}</td>
            </tr>
            <tr v-for="sub in partition.subpartitions" :key="`${partition.name}/${sub.name}`" class="sub">
              <td class="m">↳ {{ sub.name }}</td>
              <td v-if="hasBounds" />
              <td class="m">{{ formatEstimate(sub.estimatedRows) || '—' }}</td>
              <td class="m">{{ formatBytes(sub.dataBytes) }}</td>
            </tr>
          </template>
        </tbody>
      </table>
    </div>
  </section>
</template>

<style scoped>
.partitions { display: flex; flex-direction: column; gap: 10px; margin-top: 28px; min-width: 0; }
h2 { font-size: 22px; font-weight: 600; }
.summary { margin: 0; font-size: 13px; line-height: 1.6; }
code { font: 500 12.5px var(--font-mono); }
.box { border: 1px solid var(--line); border-radius: 8px; overflow-x: auto; background: var(--s1); }
table { border-collapse: collapse; width: 100%; font-size: 13px; }
th { text-align: left; font: 500 11px var(--font-mono); letter-spacing: 0.06em; text-transform: uppercase; color: var(--mut); padding: 9px 16px; background: var(--s2); border-bottom: 1px solid var(--line); white-space: nowrap; }
td { padding: 10px 16px; border-bottom: 1px solid var(--line-soft); white-space: nowrap; }
tr:last-child td { border-bottom: 0; }
.m { font-family: var(--font-mono); font-size: 12.5px; }
.dim { color: var(--mut); }
.sub td { color: var(--mut); }
.tag { display: inline-flex; align-items: center; height: 20px; padding: 0 8px; margin-left: 6px; border-radius: 4px; font: 500 11px var(--font-mono); border: 1px solid var(--line); color: var(--mut); }
</style>
