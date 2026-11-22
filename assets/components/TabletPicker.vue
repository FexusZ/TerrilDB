<script setup lang="ts">
import { computed } from 'vue'
import type { VitessTablet } from '../api/types'
import { useWorkspaceStore } from '../stores/workspace'

const workspace = useWorkspaceStore()

const ROLES: Record<string, string> = { PRIMARY: 'primaire', REPLICA: 'réplica', RDONLY: 'lecture seule' }
const label = (tablet: VitessTablet): string => `${tablet.alias} · ${ROLES[tablet.type] ?? tablet.type.toLowerCase()} · ${tablet.keyspace}/${tablet.shard}${tablet.serving ? '' : ' · hors service'}`
const TYPE_WORDS: Record<string, string> = {
  BACKUP: 'en sauvegarde',
  RESTORE: 'en restauration',
  DRAINED: 'vidé',
  SPARE: 'de rechange',
  EXPERIMENTAL: 'expérimental',
  BATCH: 'batch',
  UNKNOWN: 'de type inconnu',
  MASTER: 'primaire (ancien nom)',
}
// Le serveur écarte ce qu'il ne sait pas viser : on le dit (combien, et pourquoi) au lieu de laisser croire qu'un tablet a disparu.
const skippedNote = computed(() => {
  const counts = new Map<string, number>()
  for (const tablet of workspace.skippedTablets) {
    const word = tablet.reason === 'unexpected_name' ? 'au nom inhabituel' : tablet.reason === 'unreadable' ? 'à la ligne illisible' : (tablet.type !== null ? TYPE_WORDS[tablet.type] : undefined) ?? 'de type inconnu'
    counts.set(word, (counts.get(word) ?? 0) + 1)
  }
  const total = workspace.skippedTablets.length
  if (total === 0) {
    return null
  }
  const parts = [...counts].map(([word, count]) => `${count} ${word}`)

  return `${total} ${total === 1 ? 'tablet n’est pas proposé' : 'tablets ne sont pas proposés'} (ils ne peuvent pas être lus tablet par tablet) : ${parts.join(', ')}.`
})
const chosen = computed({
  get: () => workspace.tablet ?? '',
  set: (value: string) => workspace.selectTablet(value === '' ? null : value),
})
</script>

<template>
  <section class="tablet" aria-label="Tablet lu">
    <label>
      <span>Tablet</span>
      <select v-model="chosen">
        <option value="">Choisir un tablet…</option>
        <option v-for="tablet in workspace.tablets" :key="tablet.alias" :value="tablet.alias">{{ label(tablet) }}</option>
      </select>
    </label>
    <p v-if="skippedNote" class="note skipped">{{ skippedNote }}</p>
    <p v-if="workspace.needsTablet" class="note">
      Sur Vitess, vtgate envoie chaque lecture à un seul tablet, choisi au hasard : TerrilDB ne l’affiche pas pour ne pas faire passer l’état d’un tablet pour celui du cluster. Choisissez le tablet à lire.
    </p>
    <p v-else class="note">Vous lisez le tablet {{ workspace.tablet }} : ces pages décrivent ce serveur MySQL, pas le cluster entier.</p>
  </section>
</template>

<style scoped>
.tablet { display: grid; gap: 8px; padding: 10px 12px; margin-bottom: 12px; border-radius: var(--radius); background: var(--s1); border: 1px solid var(--line); }
label { display: flex; align-items: center; gap: 10px; font-size: 13px; }
select { min-width: 0; max-width: 100%; padding: 4px 8px; border-radius: var(--radius); background: var(--bg); border: 1px solid var(--line-strong); color: var(--fg); font: inherit; }
.note { margin: 0; color: var(--mut); font-size: 13px; }
</style>
