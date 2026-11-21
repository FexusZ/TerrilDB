import { api } from './client'
import type { StructureScope } from './structure'

export type StepVerdict = 'allowed' | 'needs_confirmation' | 'refused'

export interface ScriptStepPreview {
  index: number
  sql: string
  verdict: StepVerdict
  kind: 'read' | 'inspect' | 'write' | 'schema' | null
  reason: string | null
}

/** Ce que le serveur dit d'un script AVANT de l'exécuter : le verdict de chaque instruction et ce que le moteur garantit. */
export interface ScriptPreview {
  steps: ScriptStepPreview[]
  canRun: boolean
  needsConfirmation: boolean
  destructiveCount: number
  firstRefused: { index: number; reason: string | null } | null
  transactional: boolean
  /** Une erreur annule le script ENTIER. */
  atomic: boolean
  dryRun: { available: boolean; reason: 'no_transaction' | 'implicit_commit' | 'non_transactional_tables' | null }
  warnings: Array<'mixed_data_and_ddl' | 'ddl_not_transactional' | 'no_transaction' | 'non_transactional_tables'>
}

export type ScriptStepStatus = 'applied' | 'rolled_back' | 'failed' | 'not_run'

export interface ScriptStepResult {
  index: number
  sql: string
  status: ScriptStepStatus
  affectedRows: number | null
  returnedRows: number | null
  /** La lecture avait plus de lignes que la limite par instruction (1000) : le surplus n'est pas rendu. */
  truncated: boolean
  error: string | null
  durationMs: number
}

export interface ScriptRun {
  executionId: string
  status: 'committed' | 'dry_run' | 'rolled_back' | 'partial' | 'failed'
  dryRun: boolean
  failureReason: 'engine_error' | 'timeout' | 'cancelled' | null
  applied: number[]
  failed: number | null
  notRun: number[]
  steps: ScriptStepResult[]
}

const withScope = <T extends object>(body: T, scope: StructureScope): T & Partial<StructureScope> => ({
  ...body,
  ...(scope.database === null ? {} : { database: scope.database }),
  ...(scope.schema === null ? {} : { schema: scope.schema }),
})

/** Juge chaque instruction sans rien exécuter. */
export const previewScript = (id: string, script: string, scope: StructureScope): Promise<ScriptPreview> =>
  api<ScriptPreview>('POST', `/web/connections/${id}/script/preview`, withScope({ script }, scope))

/** Exécute le script ; `confirmed` couvre toutes les instructions destructrices à la fois, `dryRun` annule tout à la fin. */
export const executeScript = (id: string, script: string, options: { confirmed: boolean; dryRun: boolean }, scope: StructureScope): Promise<ScriptRun> =>
  api<ScriptRun>('POST', `/web/connections/${id}/script/execute`, withScope({ script, ...options }, scope))

export type BackgroundRunStatus = 'queued' | 'running' | 'committed' | 'dry_run' | 'rolled_back' | 'partial' | 'failed' | 'cancelled' | 'interrupted'

/** Une instruction d'une exécution en arrière-plan : rang, état, nombres et SQLSTATE. Jamais le texte (le client garde celui de l'aperçu). */
export interface BackgroundStatement {
  rank: number
  /** Tant que l'exécution n'est pas terminée, « applied » est provisoire (la transaction n'est pas validée). */
  status: ScriptStepStatus
  affectedRows: number | null
  durationMs: number
  sqlstate: string | null
}

/** Le suivi d'un script lancé en arrière-plan (#169). */
export interface BackgroundRun {
  id: string
  connectionId: string
  status: BackgroundRunStatus
  finished: boolean
  dryRun: boolean
  total: number
  completed: number
  cancelRequested: boolean
  statements: BackgroundStatement[]
  createdAt: string
  startedAt: string | null
  heartbeatAt: string
  finishedAt: string | null
}

/** Confie le script au worker : la réponse est immédiate (202), l'exécution se suit ensuite par `fetchScriptRun`. */
export const startScriptRun = (id: string, script: string, options: { confirmed: boolean; dryRun: boolean }, scope: StructureScope): Promise<{ run: BackgroundRun }> =>
  api<{ run: BackgroundRun }>('POST', `/web/connections/${id}/script/run`, withScope({ script, ...options }, scope))

export const fetchScriptRun = (runId: string): Promise<{ run: BackgroundRun }> => api<{ run: BackgroundRun }>('GET', `/web/script-runs/${runId}`)

/** Demande l'arrêt ENTRE deux instructions : celle qui tourne va à son terme, rien n'est tué. */
export const cancelScriptRun = (runId: string): Promise<{ run: BackgroundRun }> => api<{ run: BackgroundRun }>('POST', `/web/script-runs/${runId}/cancel`)


/** Les exécutions en arrière-plan du compte (les plus récentes d'abord), éventuellement pour une seule connexion. */
export const fetchScriptRuns = (connectionId: string | null, limit = 20): Promise<{ runs: BackgroundRun[] }> =>
  api<{ runs: BackgroundRun[] }>('GET', `/web/script-runs?${connectionId === null ? '' : `connection=${encodeURIComponent(connectionId)}&`}limit=${String(limit)}`)
