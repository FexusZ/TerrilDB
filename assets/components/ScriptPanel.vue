<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { reasonText, messageFor } from '../api/errors'
import { executeScript, previewScript } from '../api/script'
import type { ScriptPreview, ScriptRun, ScriptStepStatus, StepVerdict } from '../api/script'
import type { StructureScope } from '../api/structure'
import AppButton from './AppButton.vue'
import ConfirmDialog from './ConfirmDialog.vue'

const props = defineProps<{ connectionId: string; script: string; scope: StructureScope }>()

const preview = ref<ScriptPreview | null>(null)
const run = ref<ScriptRun | null>(null)
const error = ref<string | null>(null)
const busy = ref(false)
const dryRun = ref(false)
const confirming = ref(false)

const VERDICTS: Record<StepVerdict, string> = { allowed: 'Autorisée', needs_confirmation: 'À confirmer', refused: 'Refusée' }
const STATUSES: Record<ScriptStepStatus, string> = { applied: 'Appliquée', rolled_back: 'Annulée', failed: 'En échec', not_run: 'Non lancée' }
const WARNINGS: Record<ScriptPreview['warnings'][number], string> = {
  mixed_data_and_ddl: 'Ce script mêle données et structure : ce moteur valide tout ce qui précède à chaque instruction de structure, il n’est donc pas annulable en entier.',
  ddl_not_transactional: 'Les instructions de structure sont validées une à une par ce moteur : en cas d’erreur, celles qui ont réussi restent appliquées.',
  non_transactional_tables: 'Ce script écrit dans une table sans transaction (MyISAM, MEMORY, Aria…) : ce que le moteur y a écrit reste, même après une erreur, et un test sans valider est impossible.',
  no_transaction: 'Ce moteur n’a pas de transaction : en cas d’erreur, les instructions déjà passées restent appliquées.',
}

// Après un succès réel, un second clic rejouerait un script qui n'est pas forcément idempotent : il faut le modifier ou le réexaminer.
const done = computed(() => run.value?.status === 'committed')
const canRun = computed(() => preview.value?.canRun === true && !busy.value && !done.value)
const dryRunReason = computed(() => {
  const reason = preview.value?.dryRun.reason
  if (reason === 'implicit_commit') {
    return 'Indisponible : ce moteur valide la transaction à chaque instruction de structure.'
  }
  if (reason === 'non_transactional_tables') {
    return 'Indisponible : le script écrit dans une table sans transaction, rien ne s’y annule.'
  }
  return reason === 'no_transaction' ? 'Indisponible : ce moteur n’a pas de transaction.' : null
})
const refusedRank = computed(() => (preview.value?.firstRefused ? preview.value.firstRefused.index + 1 : null))

// Un aperçu ne vaut que pour le texte qu'il a jugé.
watch(() => props.script, () => {
  preview.value = null
  run.value = null
  error.value = null
  dryRun.value = false
})

async function doPreview(): Promise<void> {
  busy.value = true
  error.value = null
  run.value = null
  try {
    preview.value = await previewScript(props.connectionId, props.script, props.scope)
    if (!preview.value.dryRun.available) {
      dryRun.value = false
    }
  } catch (e) {
    preview.value = null
    error.value = messageFor(e)
  } finally {
    busy.value = false
  }
}

async function send(confirmed: boolean): Promise<void> {
  confirming.value = false
  busy.value = true
  error.value = null
  try {
    run.value = await executeScript(props.connectionId, props.script, { confirmed, dryRun: dryRun.value }, props.scope)
  } catch (e) {
    error.value = messageFor(e)
  } finally {
    busy.value = false
  }
}

function execute(): void {
  if (preview.value?.needsConfirmation) {
    confirming.value = true
    return
  }
  void send(false)
}

const plural = (n: number, one: string, many: string): string => `${String(n)} ${n > 1 ? many : one}`

const outcome = computed(() => {
  const r = run.value
  if (r === null) {
    return ''
  }
  const rank = r.failed === null ? 0 : r.failed + 1
  switch (r.status) {
    case 'committed':
      return `Script exécuté : ${plural(r.applied.length, 'instruction appliquée', 'instructions appliquées')}.`
    case 'dry_run':
      return 'Test réussi : tout a été exécuté puis annulé, rien n’a été validé.'
    case 'rolled_back':
      return `Échec à l’instruction ${String(rank)} : tout a été annulé.`
    case 'partial':
      return `Échec à l’instruction ${String(rank)} : ${r.applied.length > 1 ? `${String(r.applied.length)} instructions restent appliquées` : '1 instruction reste appliquée'}, les suivantes ne sont pas parties.`
    default:
      return `Échec à l’instruction ${String(rank)} : rien n’est resté appliqué.`
  }
})
const failedRun = computed(() => run.value !== null && run.value.failed !== null)
</script>

<template>
  <section class="script" aria-label="Exécuter un script">
    <p class="intro">Le texte de l’éditeur est découpé en instructions, chacune jugée avant que quoi que ce soit parte. Le script s’arrête à la première erreur.</p>

    <div class="actions">
      <AppButton class="preview" small :busy="busy" :disabled="script.trim() === ''" @click="doPreview">Aperçu du script</AppButton>
      <label class="dry">
        <input v-model="dryRun" class="dry-run" type="checkbox" :disabled="!preview || !preview.dryRun.available">
        Tester sans valider
      </label>
      <AppButton class="execute" small variant="primary" :disabled="!canRun" @click="execute">{{ dryRun ? 'Tester le script' : 'Exécuter le script' }}</AppButton>
    </div>
    <p v-if="preview && dryRunReason" class="dry-run-reason note">{{ dryRunReason }}</p>
    <p v-else-if="preview && dryRun" class="note">Tout est exécuté pour de vrai dans la transaction, puis annulé. Des verrous sont posés le temps du test et les compteurs automatiques (séquences, auto-incréments) avancent malgré l’annulation.</p>

    <ul v-if="preview && preview.warnings.length > 0" class="warnings">
      <li v-for="warning in preview.warnings" :key="warning">{{ WARNINGS[warning] }}</li>
    </ul>
    <p v-if="refusedRank !== null" class="refused-note" role="alert">L’instruction {{ refusedRank }} est refusée : {{ reasonText(preview?.firstRefused?.reason) }} Rien ne partira.</p>
    <p v-if="error" class="error" role="alert">{{ error }}</p>

    <ol v-if="run" class="steps result" aria-label="Compte rendu">
      <li v-for="step in run.steps" :key="step.index" class="step" :data-status="step.status">
        <span class="rank">{{ step.index + 1 }}</span>
        <code>{{ step.sql }}</code>
        <span class="chip">{{ STATUSES[step.status] }}</span>
        <span v-if="step.error" class="step-error">{{ step.error }}</span>
      </li>
    </ol>
    <p v-if="run" class="outcome" :class="{ bad: failedRun }" role="status">{{ outcome }}</p>

    <ol v-else-if="preview" class="steps" aria-label="Aperçu">
      <li v-for="step in preview.steps" :key="step.index" class="step" :data-verdict="step.verdict">
        <span class="rank">{{ step.index + 1 }}</span>
        <code>{{ step.sql }}</code>
        <span class="chip">{{ VERDICTS[step.verdict] }}</span>
        <span v-if="step.reason" class="step-error">{{ reasonText(step.reason) }}</span>
      </li>
    </ol>

    <ConfirmDialog
      v-if="confirming && preview"
      title="Exécuter ce script ?"
      :message="`Ce script contient ${plural(preview.destructiveCount, 'instruction destructrice', 'instructions destructrices')}. Une seule confirmation couvre tout le script.`"
      :confirm-label="dryRun ? 'Tester quand même' : 'Exécuter quand même'"
      danger
      @confirm="send(true)"
      @cancel="confirming = false"
    />
  </section>
</template>

<style scoped>
.script { display: flex; flex-direction: column; gap: 10px; padding: 12px 16px; border-bottom: 1px solid var(--line); background: var(--s1); }
.intro, .note { color: var(--mut); font-size: 13px; }
.actions { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; }
.dry { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; color: var(--fg); }
.warnings { margin: 0; padding: 8px 12px 8px 28px; border-radius: var(--radius); background: var(--sec-bg); border: 1px solid var(--sec-line); color: var(--sec-ink); font-size: 13px; }
.refused-note, .error { padding: 8px 12px; border-radius: var(--radius); background: var(--bad-bg); border: 1px solid var(--bad-line); color: var(--bad); font-size: 13px; }
.steps { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 4px; max-height: 32vh; overflow: auto; }
.step { display: grid; grid-template-columns: 2.5em minmax(0, 1fr) auto; gap: 8px; align-items: baseline; padding: 4px 8px; border-radius: var(--radius); background: var(--bg); border: 1px solid var(--line); }
.step code { font: 400 12px var(--font-mono); white-space: pre-wrap; overflow-wrap: anywhere; }
.rank { color: var(--mut); font: 400 11px var(--font-mono); text-align: right; }
.chip { font-size: 11px; color: var(--mut); }
.step[data-verdict='refused'] .chip, .step[data-status='failed'] .chip { color: var(--bad); }
.step[data-verdict='needs_confirmation'] .chip { color: var(--sec); }
.step-error { grid-column: 2 / -1; font-size: 12px; color: var(--bad); white-space: pre-wrap; }
.outcome { font-weight: 600; font-size: 14px; }
.outcome.bad { color: var(--bad); }
</style>
