import { api } from './client'
import type { StructureScope } from './structure'
import type { DataPayload } from '../utils/dataDraft'

/** Une instruction du plan, avec les valeurs en clair (pour la montrer) et combien de lignes elle retrouve (`matched`, nul pour un ajout). */
export interface DataStatement {
  sql: string
  operation: 'update' | 'insert' | 'delete'
  subject: string
  destructive: boolean
  matched: number | null
}

export interface DataPlan {
  statements: DataStatement[]
  transactional: boolean
  /** ClickHouse : modifications et suppressions s'appliquent en arrière-plan, elles ne se voient pas tout de suite. */
  asynchronous: boolean
  destructive: boolean
}

export interface DataPreview extends DataPlan {
  /** Chaque modification ou suppression retrouve exactement une ligne. */
  ok: boolean
}

const url = (id: string, table: string, action: 'preview' | 'apply'): string => `/web/connections/${id}/tables/${encodeURIComponent(table)}/data/${action}`

function withScope<T extends object>(body: T, scope: StructureScope): T & Partial<StructureScope> {
  return { ...body, ...(scope.database === null ? {} : { database: scope.database }), ...(scope.schema === null ? {} : { schema: scope.schema }) }
}

/** Les instructions que ces lignes donneraient, sans rien écrire. */
export const previewData = (id: string, table: string, payload: DataPayload, scope: StructureScope): Promise<DataPreview> =>
  api<DataPreview>('POST', url(id, table, 'preview'), withScope(payload, scope))

/** Applique les changements ; `confirmed` est exigé par le serveur quand des lignes sont supprimées. */
export const applyData = (id: string, table: string, payload: DataPayload, confirmed: boolean, scope: StructureScope): Promise<DataPlan> =>
  api<DataPlan>('POST', url(id, table, 'apply'), withScope({ ...payload, confirmed }, scope))
