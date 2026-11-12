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
  dryRun: { available: boolean; reason: 'no_transaction' | 'implicit_commit' | null }
  warnings: Array<'mixed_data_and_ddl' | 'ddl_not_transactional' | 'no_transaction'>
}

export type ScriptStepStatus = 'applied' | 'rolled_back' | 'failed' | 'not_run'

export interface ScriptStepResult {
  index: number
  sql: string
  status: ScriptStepStatus
  affectedRows: number | null
  returnedRows: number | null
  error: string | null
  durationMs: number
}

export interface ScriptRun {
  executionId: string
  status: 'committed' | 'dry_run' | 'rolled_back' | 'partial' | 'failed'
  dryRun: boolean
  failureReason: 'engine_error' | 'timeout' | null
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
