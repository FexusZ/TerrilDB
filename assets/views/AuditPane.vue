<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { api } from '../api/client'
import { messageFor } from '../api/errors'
import type { SchemaAudit, SchemaFinding } from '../api/types'
import AppButton from '../components/AppButton.vue'
import { useWorkspaceStore } from '../stores/workspace'
import { describeSchemaFinding } from '../utils/optimizer'

const props = defineProps<{ id: string }>()
const workspace = useWorkspaceStore()
const router = useRouter()

const audit = ref<SchemaAudit | null>(null)
const error = ref<string | null>(null)

const criticals = computed(() => audit.value?.findings.filter((finding) => finding.severity === 'critical') ?? [])
const warnings = computed(() => audit.value?.findings.filter((finding) => finding.severity === 'warning') ?? [])
const infos = computed(() => audit.value?.findings.filter((finding) => finding.severity === 'info') ?? [])

onMounted(async () => {
  try {
    audit.value = await api<SchemaAudit>('GET', `/web/connections/${props.id}/audit${workspace.scopeSuffix()}`)
  } catch (failure) {
    error.value = messageFor(failure)
  }
})

/** La suggestion est du texte à relire : on l'ouvre dans l'éditeur, on ne la lance pas. */
async function open(finding: SchemaFinding): Promise<void> {
  if (finding.suggestion === null) {
    return
  }
  workspace.addTab(finding.suggestion)
  await router.push({ name: 'workspace', params: { id: props.id } })
}
</script>

<template>
  <section class="pane">
    <div class="head">
      <h1>Audit du schéma</h1>
      <p v-if="audit" class="meta">{{ audit.tablesAudited }} {{ audit.tablesAudited > 1 ? 'tables examinées' : 'table examinée' }}. Les suggestions sont du SQL à relire : rien n’est modifié sans vous.</p>
    </div>

    <p v-if="error" class="error" role="alert">{{ error }}</p>
    <p v-else-if="!audit" class="meta" role="status">Analyse en cours…</p>
    <template v-else>
      <p v-if="audit.truncated" class="truncated" role="status">L’audit s’est arrêté aux 300 premières tables.</p>
      <p v-if="audit.findings.length === 0" class="clean">Rien à signaler : chaque table a sa clé primaire, chaque clé étrangère son index, et aucun index n’est superflu.</p>

      <template v-for="group in [{ title: 'Urgent', items: criticals }, { title: 'À corriger', items: warnings }, { title: 'À savoir', items: infos }]" :key="group.title">
        <section v-if="group.items.length > 0" class="group">
          <h2>{{ group.title }} · {{ group.items.length }}</h2>
          <ul>
            <li v-for="(finding, index) in group.items" :key="index" class="finding" :class="finding.severity">
              <p class="title">{{ describeSchemaFinding(finding).title }}</p>
              <p class="help">{{ describeSchemaFinding(finding).help }}</p>
              <template v-if="finding.suggestion">
                <pre>{{ finding.suggestion }}</pre>
                <AppButton class="open" small @click="open(finding)">Ouvrir dans l’éditeur</AppButton>
              </template>
            </li>
          </ul>
        </section>
      </template>
    </template>
  </section>
</template>

<style scoped>
.pane { flex: 1; min-width: 0; overflow: auto; padding: 24px 28px 48px; display: flex; flex-direction: column; gap: 18px; }
h1 { font-size: 30px; line-height: 1.1; font-weight: 600; }
h2 { font-size: 15px; font-weight: 600; margin-bottom: 8px; }
.head { display: flex; flex-direction: column; gap: 4px; }
.meta, .help { color: var(--mut); font-size: 13px; }
ul { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 10px; }
.finding { padding: 14px 16px; border-radius: 8px; border: 1px solid var(--line); background: var(--s1); display: flex; flex-direction: column; gap: 6px; align-items: flex-start; }
.finding.warning { box-shadow: inset 3px 0 0 var(--sec); }
.finding.critical { box-shadow: inset 3px 0 0 var(--bad); }
.title { font-weight: 500; }
pre { margin: 4px 0 0; padding: 8px 10px; border-radius: var(--radius); background: var(--bg); border: 1px solid var(--line); font: 400 12.5px/1.5 var(--font-mono); overflow-x: auto; max-width: 100%; }
.clean { padding: 12px 14px; border-radius: var(--radius); background: var(--ok-bg); border: 1px solid var(--ok-line); color: var(--ok); }
.truncated { padding: 10px 12px; border-radius: var(--radius); background: var(--sec-bg); border: 1px solid var(--sec-line); color: var(--sec-ink); font-size: 13px; }
.error { padding: 10px 12px; border-radius: var(--radius); background: var(--bad-bg); border: 1px solid var(--bad-line); color: var(--bad); font-size: 13px; }
</style>
