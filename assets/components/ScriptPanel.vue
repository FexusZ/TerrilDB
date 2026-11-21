<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { ApiError } from '../api/client'
import { reasonText, messageFor } from '../api/errors'
import { cancelScriptRun, executeScript, fetchScriptRun, fetchScriptRuns, previewScript, startScriptRun } from '../api/script'
import type { BackgroundRun, BackgroundRunStatus, ScriptPreview, ScriptRun, ScriptStepStatus, StepVerdict } from '../api/script'
import type { StructureScope } from '../api/structure'
import { readSqlFile, SQL_FILE_MESSAGES } from '../utils/sqlFile'
import AppButton from './AppButton.vue'
import ConfirmDialog from './ConfirmDialog.vue'

const props = defineProps<{ connectionId: string; script: string; scope: StructureScope }>()
// Le texte lu d'un fichier est rendu à l'éditeur : il ne part nulle part avant l'aperçu, que l'on déclenche ensuite.
const emit = defineEmits<{ import: [text: string] }>()

const preview = ref<ScriptPreview | null>(null)
const run = ref<ScriptRun | null>(null)
const error = ref<string | null>(null)
const busy = ref(false)
const dryRun = ref(false)
const confirming = ref(false)
// Vitess : le serveur a dit qu'un flux ne suivra pas une instruction de structure (il lit la cible, pas l'aperçu) : même confirmation unique.
const streamWarning = ref(false)
const fileInput = ref<HTMLInputElement | null>(null)
const fileError = ref<string | null>(null)
const pendingImport = ref<{ text: string; name: string } | null>(null)
// Exécution en arrière-plan : le worker la joue, ce panneau la suit toutes les 2 s tant qu'il est affiché.
const background = ref(false)
const bgRun = ref<BackgroundRun | null>(null)
let pollTimer: ReturnType<typeof setTimeout> | null = null
let pollFailures = 0
const POLL_EVERY_MS = 2000
const POLL_FAILURES_MAX = 5

const VERDICTS: Record<StepVerdict, string> = { allowed: 'Autorisée', needs_confirmation: 'À confirmer', refused: 'Refusée' }
const STATUSES: Record<ScriptStepStatus, string> = { applied: 'Appliquée', rolled_back: 'Annulée', failed: 'En échec', not_run: 'Non lancée' }
const WARNINGS: Record<ScriptPreview['warnings'][number], string> = {
  mixed_data_and_ddl: 'Ce script mêle données et structure : ce moteur valide tout ce qui précède à chaque instruction de structure, il n’est donc pas annulable en entier.',
  ddl_not_transactional: 'Les instructions de structure sont validées une à une par ce moteur : en cas d’erreur, celles qui ont réussi restent appliquées.',
  non_transactional_tables: 'Ce script écrit dans une table sans transaction (MyISAM, MEMORY, Aria…) : ce que le moteur y a écrit reste, même après une erreur, et un test sans valider est impossible.',
  no_transaction: 'Ce moteur n’a pas de transaction : en cas d’erreur, les instructions déjà passées restent appliquées.',
}

// Après un succès réel, un second clic rejouerait un script qui n'est pas forcément idempotent : il faut le modifier ou le réexaminer.
const done = computed(() => run.value?.status === 'committed' || bgRun.value?.status === 'committed')
// Une exécution en arrière-plan vivante occupe le panneau : en lancer une seconde sur le même texte la doublerait.
const following = computed(() => bgRun.value !== null && !bgRun.value.finished)
const canRun = computed(() => preview.value?.canRun === true && !busy.value && !done.value && !following.value)
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

// Les exécutions récentes de cette connexion (#169) : lues à l'ouverture de la liste, jamais le texte des scripts.
const recentOpen = ref(false)
const recent = ref<BackgroundRun[] | null>(null)
const recentError = ref<string | null>(null)
// Une exécution reprise de la liste n'a pas de texte à montrer : l'aperçu courant jugeait peut-être un autre script.
const picked = ref(false)

const RUN_STATUSES: Record<BackgroundRunStatus, string> = {
  queued: 'En attente',
  running: 'En cours',
  committed: 'Exécuté',
  dry_run: 'Test réussi',
  rolled_back: 'Annulé',
  partial: 'Partiel',
  failed: 'En échec',
  cancelled: 'Arrêté',
  interrupted: 'Interrompu',
}

async function toggleRecent(): Promise<void> {
  recentOpen.value = !recentOpen.value
  if (!recentOpen.value) {
    return
  }
  recentError.value = null
  try {
    recent.value = (await fetchScriptRuns(props.connectionId)).runs
  } catch (e) {
    recentError.value = messageFor(e)
  }
}

function follow(chosen: BackgroundRun): void {
  stopPolling()
  pollFailures = 0
  error.value = null
  run.value = null
  picked.value = true
  bgRun.value = chosen
  if (!chosen.finished) {
    schedulePoll(chosen.id)
  }
}

function stopPolling(): void {
  if (pollTimer !== null) {
    clearTimeout(pollTimer)
    pollTimer = null
  }
}

onBeforeUnmount(stopPolling)

// Un aperçu ne vaut que pour le texte qu'il a jugé.
watch(() => props.script, () => {
  stopPolling()
  preview.value = null
  run.value = null
  bgRun.value = null
  error.value = null
  dryRun.value = false
})

async function onFile(event: Event): Promise<void> {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  fileError.value = null
  if (!file) {
    return
  }
  const result = await readSqlFile(file)
  // Même fichier choisi deux fois de suite : sans remise à zéro, le navigateur ne signalerait rien.
  input.value = ''
  if (!result.ok) {
    fileError.value = SQL_FILE_MESSAGES[result.reason]
    return
  }
  if (props.script.trim() === '') {
    emit('import', result.text)
    return
  }
  pendingImport.value = { text: result.text, name: file.name }
}

function confirmImport(): void {
  if (pendingImport.value) {
    emit('import', pendingImport.value.text)
  }
  pendingImport.value = null
}

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

function schedulePoll(id: string): void {
  stopPolling()
  pollTimer = setTimeout(() => void poll(id), POLL_EVERY_MS)
}

async function poll(id: string): Promise<void> {
  try {
    bgRun.value = (await fetchScriptRun(id)).run
    pollFailures = 0
    error.value = null
  } catch (e) {
    pollFailures += 1
    if (pollFailures >= POLL_FAILURES_MAX) {
      // L'exécution continue côté serveur : on le dit, on cesse seulement de la suivre d'ici.
      error.value = `${messageFor(e)} Le suivi est interrompu ; le script, lui, continue sur le serveur.`
      return
    }
  }
  if (bgRun.value !== null && !bgRun.value.finished) {
    schedulePoll(id)
  }
}

async function startInBackground(confirmed: boolean): Promise<void> {
  confirming.value = false
  busy.value = true
  error.value = null
  stopPolling()
  pollFailures = 0
  picked.value = false
  try {
    bgRun.value = (await startScriptRun(props.connectionId, props.script, { confirmed, dryRun: dryRun.value }, props.scope)).run
    schedulePoll(bgRun.value.id)
  } catch (e) {
    error.value = messageFor(e)
  } finally {
    busy.value = false
  }
}

async function cancelRun(): Promise<void> {
  if (bgRun.value === null) {
    return
  }
  try {
    bgRun.value = (await cancelScriptRun(bgRun.value.id)).run
  } catch (e) {
    error.value = messageFor(e)
  }
}

async function send(confirmed: boolean): Promise<void> {
  if (background.value) {
    await startInBackground(confirmed)
    return
  }
  confirming.value = false
  streamWarning.value = false
  busy.value = true
  error.value = null
  try {
    run.value = await executeScript(props.connectionId, props.script, { confirmed, dryRun: dryRun.value }, props.scope)
  } catch (e) {
    if (!confirmed && e instanceof ApiError && e.code === 'confirmation_required' && e.details.reason === 'stream_source') {
      streamWarning.value = true
      confirming.value = true
    } else {
      error.value = messageFor(e)
    }
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

/** Les instructions à confirmer : celles qui détruisent, et celles qui sortent du cadre habituel (avertissement, pas un refus). */
function confirmationMessage(shown: ScriptPreview): string {
  const outside = shown.steps.filter((step) => step.verdict === 'needs_confirmation' && step.reason === 'forbidden_construct').length
  const destructive = shown.destructiveCount - outside
  const parts: string[] = []
  if (destructive > 0) {
    parts.push(plural(destructive, 'instruction destructrice', 'instructions destructrices'))
  }
  if (outside > 0) {
    parts.push(plural(outside, 'instruction hors du cadre habituel (fichiers du serveur, verrous…)', 'instructions hors du cadre habituel (fichiers du serveur, verrous…)'))
  }

  return `Ce script contient ${parts.join(' et ')}. Une seule confirmation couvre tout le script.`
}

// Lignes et durée d'une instruction partie ; rien pour une instruction jamais lancée.
function metrics(step: ScriptRun['steps'][number]): string {
  if (step.status === 'not_run') {
    return ''
  }
  const parts: string[] = []
  if (step.affectedRows !== null) {
    parts.push(plural(step.affectedRows, 'ligne affectée', 'lignes affectées'))
  }
  if (step.returnedRows !== null) {
    parts.push(`${plural(step.returnedRows, 'ligne lue', 'lignes lues')}${step.truncated ? ' (tronquée)' : ''}`)
  }
  parts.push(`${String(step.durationMs)} ms`)

  return parts.join(' · ')
}

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

const BG_STATEMENTS: Record<ScriptStepStatus, string> = { applied: 'Exécutée', rolled_back: 'Annulée', failed: 'En échec', not_run: 'Non lancée' }

// Le suivi ne porte jamais le texte : on le retrouve par le rang dans l'aperçu que l'on vient de juger.
const sqlOf = (rank: number): string => (picked.value ? '' : (preview.value?.steps[rank - 1]?.sql ?? ''))
const bgApplied = computed(() => bgRun.value?.statements.filter((s) => s.status === 'applied').length ?? 0)
const bgFailedRank = computed(() => bgRun.value?.statements.find((s) => s.status === 'failed')?.rank ?? null)

const bgOutcome = computed(() => {
  const r = bgRun.value
  if (r === null) {
    return ''
  }
  const remain = bgApplied.value > 1 ? `${String(bgApplied.value)} instructions restent appliquées` : '1 instruction reste appliquée'
  switch (r.status) {
    case 'queued':
      return 'En attente du worker…'
    case 'running':
      return `Instruction ${String(r.completed)} sur ${String(r.total)} terminée…`
    case 'committed':
      return `Script exécuté en arrière-plan : ${plural(bgApplied.value, 'instruction appliquée', 'instructions appliquées')}.`
    case 'dry_run':
      return 'Test réussi : tout a été exécuté puis annulé, rien n’a été validé.'
    case 'rolled_back':
      return `Échec à l’instruction ${String(bgFailedRank.value ?? 0)} : tout a été annulé.`
    case 'partial':
      return `Échec ou arrêt à l’instruction ${String(bgFailedRank.value ?? r.completed + 1)} : ${bgApplied.value > 0 ? remain : 'rien n’est resté appliqué'}, les suivantes ne sont pas parties.`
    case 'cancelled':
      return `Script arrêté à votre demande après ${plural(r.completed, 'instruction', 'instructions')} : ${bgApplied.value > 0 ? remain : 'rien n’est resté appliqué'}.`
    case 'interrupted':
      return `Plus de signe de vie du worker (redémarrage ou panne) : ${plural(r.completed, 'instruction était terminée', 'instructions étaient terminées')}. Vérifiez l’état de la base avant de relancer : ce que le moteur a déjà validé reste appliqué.`
    default:
      return 'Le script n’a pas pu être joué (cible injoignable, instruction refusée ou erreur du worker). Rien n’est resté appliqué.'
  }
})
const bgBad = computed(() => bgRun.value !== null && ['rolled_back', 'partial', 'failed', 'interrupted'].includes(bgRun.value.status))
</script>

<template>
  <section class="script" aria-label="Exécuter un script">
    <p class="intro">Le texte de l’éditeur est découpé en instructions, chacune jugée avant que quoi que ce soit parte. Le script s’arrête à la première erreur. La directive DELIMITER n’est pas prise en charge.</p>

    <div class="actions">
      <AppButton class="import" small @click="fileInput?.click()">Importer un fichier .sql</AppButton>
      <input ref="fileInput" class="file" type="file" accept=".sql,.txt,text/plain,application/sql" hidden aria-label="Fichier SQL à importer" @change="onFile">
      <AppButton class="preview" small :busy="busy" :disabled="script.trim() === ''" @click="doPreview">Aperçu du script</AppButton>
      <label class="dry">
        <input v-model="dryRun" class="dry-run" type="checkbox" :disabled="!preview || !preview.dryRun.available">
        Tester sans valider
      </label>
      <label class="bg" title="Le script continue sur le serveur même si vous quittez cette page, sans la limite de durée d’une requête.">
        <input v-model="background" class="background" type="checkbox" :disabled="!preview || following">
        En arrière-plan
      </label>
      <AppButton class="execute" small variant="primary" :disabled="!canRun" @click="execute">{{ background ? 'Lancer en arrière-plan' : dryRun ? 'Tester le script' : 'Exécuter le script' }}</AppButton>
    </div>
    <div class="recent">
      <AppButton class="recent" small :aria-expanded="recentOpen" @click="toggleRecent">Exécutions récentes</AppButton>
      <p v-if="recentError" class="recent-error error" role="alert">{{ recentError }}</p>
      <template v-else-if="recentOpen && recent !== null">
        <p v-if="recent.length === 0" class="recent-empty note">Aucune exécution en arrière-plan pour cette connexion.</p>
        <ul v-else class="recent-runs" aria-label="Exécutions récentes">
          <li v-for="item in recent" :key="item.id">
            <span class="when">{{ new Date(item.createdAt).toLocaleString('fr-FR') }}</span>
            <span class="chip">{{ RUN_STATUSES[item.status] }}{{ item.dryRun ? ' (test)' : '' }}</span>
            <span class="metrics">{{ item.completed }} sur {{ item.total }}</span>
            <AppButton class="follow" small @click="follow(item)">{{ item.finished ? 'Voir' : 'Suivre' }}</AppButton>
          </li>
        </ul>
      </template>
    </div>
    <p v-if="preview && dryRunReason" class="dry-run-reason note">{{ dryRunReason }}</p>
    <p v-else-if="preview && dryRun" class="note">Tout est exécuté pour de vrai dans la transaction, puis annulé. Des verrous sont posés le temps du test et les compteurs automatiques (séquences, auto-incréments) avancent malgré l’annulation.</p>

    <ul v-if="preview && preview.warnings.length > 0" class="warnings">
      <li v-for="warning in preview.warnings" :key="warning">{{ WARNINGS[warning] }}</li>
    </ul>
    <p v-if="fileError" class="file-error error" role="alert">{{ fileError }}</p>
    <p v-if="refusedRank !== null" class="refused-note" role="alert">L’instruction {{ refusedRank }} est refusée : {{ reasonText(preview?.firstRefused?.reason) }} Rien ne partira.</p>
    <p v-if="error" class="error" role="alert">{{ error }}</p>

    <template v-if="bgRun">
      <ol v-if="bgRun.statements.length > 0" class="steps result" aria-label="Suivi de l’exécution">
        <li v-for="statement in bgRun.statements" :key="statement.rank" class="step" :data-status="statement.status">
          <span class="rank">{{ statement.rank }}</span>
          <code>{{ sqlOf(statement.rank) }}</code>
          <span class="chip">{{ statement.status === 'applied' && !bgRun.finished ? 'Exécutée' : BG_STATEMENTS[statement.status] }}</span>
          <span v-if="statement.status !== 'not_run'" class="metrics">{{ statement.affectedRows !== null ? `${plural(statement.affectedRows, 'ligne', 'lignes')} · ` : '' }}{{ statement.durationMs }} ms</span>
          <span v-if="statement.sqlstate" class="step-error">Code SQLSTATE {{ statement.sqlstate }}</span>
        </li>
      </ol>
      <p class="outcome" :class="{ bad: bgBad }" role="status">{{ bgOutcome }}</p>
      <div v-if="!bgRun.finished" class="cancel">
        <AppButton class="cancel-run" small :disabled="bgRun.cancelRequested" @click="cancelRun">Demander l’arrêt</AppButton>
        <span class="cancel-note note">{{ bgRun.cancelRequested ? 'Arrêt demandé : l’instruction en cours ira jusqu’au bout, les suivantes ne partiront pas.' : 'L’arrêt se fait entre deux instructions : celle qui tourne n’est jamais interrompue.' }}</span>
      </div>
    </template>

    <ol v-else-if="run" class="steps result" aria-label="Compte rendu">
      <li v-for="step in run.steps" :key="step.index" class="step" :data-status="step.status">
        <span class="rank">{{ step.index + 1 }}</span>
        <code>{{ step.sql }}</code>
        <span class="chip">{{ STATUSES[step.status] }}</span>
        <span v-if="metrics(step)" class="metrics">{{ metrics(step) }}</span>
        <span v-if="step.error" class="step-error">{{ step.error }}</span>
      </li>
    </ol>
    <p v-if="run && !bgRun" class="outcome" :class="{ bad: failedRun }" role="status">{{ outcome }}</p>

    <ol v-if="preview && !run && !bgRun" class="steps" aria-label="Aperçu">
      <li v-for="step in preview.steps" :key="step.index" class="step" :data-verdict="step.verdict">
        <span class="rank">{{ step.index + 1 }}</span>
        <code>{{ step.sql }}</code>
        <span class="chip">{{ VERDICTS[step.verdict] }}</span>
        <span v-if="step.reason" class="step-error">{{ reasonText(step.reason, step.verdict === 'needs_confirmation') }}</span>
      </li>
    </ol>

    <ConfirmDialog
      v-if="pendingImport"
      title="Remplacer le contenu de l’éditeur ?"
      :message="`Le texte actuel de l’éditeur sera remplacé par celui de « ${pendingImport.name} ». Rien ne sera exécuté : il faudra d’abord en demander l’aperçu.`"
      confirm-label="Remplacer"
      @confirm="confirmImport"
      @cancel="pendingImport = null"
    />
    <ConfirmDialog
      v-if="confirming && preview"
      title="Exécuter ce script ?"
      :message="streamWarning ? reasonText('stream_source', true) : confirmationMessage(preview)"
      :confirm-label="dryRun ? 'Tester quand même' : 'Exécuter quand même'"
      danger
      @confirm="send(true)"
      @cancel="confirming = false; streamWarning = false"
    />
  </section>
</template>

<style scoped>
.script { display: flex; flex-direction: column; gap: 10px; padding: 12px 16px; border-bottom: 1px solid var(--line); background: var(--s1); }
.intro, .note { color: var(--mut); font-size: 13px; }
.actions { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; }
.dry, .bg { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; color: var(--fg); }
.recent { display: flex; flex-direction: column; gap: 6px; align-items: flex-start; }
.recent-runs { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 4px; max-height: 20vh; overflow: auto; align-self: stretch; }
.recent-runs li { display: flex; flex-wrap: wrap; align-items: baseline; gap: 12px; padding: 4px 8px; border-radius: var(--radius); background: var(--bg); border: 1px solid var(--line); font-size: 13px; }
.when { color: var(--mut); font: 400 12px var(--font-mono); }
.cancel { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; }
.warnings { margin: 0; padding: 8px 12px 8px 28px; border-radius: var(--radius); background: var(--sec-bg); border: 1px solid var(--sec-line); color: var(--sec-ink); font-size: 13px; }
.refused-note, .error { padding: 8px 12px; border-radius: var(--radius); background: var(--bad-bg); border: 1px solid var(--bad-line); color: var(--bad); font-size: 13px; }
.steps { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 4px; max-height: 32vh; overflow: auto; }
.step { display: grid; grid-template-columns: 2.5em minmax(0, 1fr) auto; gap: 8px; align-items: baseline; padding: 4px 8px; border-radius: var(--radius); background: var(--bg); border: 1px solid var(--line); }
.step code { font: 400 12px var(--font-mono); white-space: pre-wrap; overflow-wrap: anywhere; }
.rank { color: var(--mut); font: 400 11px var(--font-mono); text-align: right; }
.chip { font-size: 11px; color: var(--mut); }
.step[data-verdict='refused'] .chip, .step[data-status='failed'] .chip { color: var(--bad); }
.step[data-verdict='needs_confirmation'] .chip { color: var(--sec); }
.metrics { grid-column: 2 / -1; font-size: 12px; color: var(--mut); }
.step-error { grid-column: 2 / -1; font-size: 12px; color: var(--bad); white-space: pre-wrap; }
.outcome { font-weight: 600; font-size: 14px; }
.outcome.bad { color: var(--bad); }
</style>
