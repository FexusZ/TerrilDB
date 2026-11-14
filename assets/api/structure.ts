import { api } from './client'
import type { StructurePayload, StructurePlan, StructurePreview, TableDetail } from './types'

/** Ce qu'on envoie : la table voulue (`draft`) OU sa suppression (`drop`), jamais du SQL, avec l'empreinte de la table chargée. */
export interface StructureRequest {
  draft?: StructurePayload
  drop?: boolean
  fingerprint: string
}

export interface StructureScope {
  database: string | null
  schema: string | null
}

const url = (id: string, table: string, action: 'preview' | 'apply'): string => `/web/connections/${id}/tables/${encodeURIComponent(table)}/structure/${action}`

function withScope<T extends object>(body: T, scope: StructureScope): T & Partial<StructureScope> {
  return { ...body, ...(scope.database === null ? {} : { database: scope.database }), ...(scope.schema === null ? {} : { schema: scope.schema }) }
}

/** Les instructions qu'un brouillon donnerait, sans rien écrire. */
export const previewStructure = (id: string, table: string, request: StructureRequest, scope: StructureScope): Promise<StructurePreview> =>
  api<StructurePreview>('POST', url(id, table, 'preview'), withScope(request, scope))

/** Applique le brouillon ; `confirmed` est exigé par le serveur pour ce qui détruit, `confirmedTableName` (nom exact de la table) pour une table très volumineuse. Rend la nouvelle table (nulle si supprimée). */
export const applyStructure = (id: string, table: string, request: StructureRequest & { confirmed: boolean; confirmedTableName?: string }, scope: StructureScope): Promise<StructurePlan & { table: TableDetail | null }> =>
  api<StructurePlan & { table: TableDetail | null }>('POST', url(id, table, 'apply'), withScope(request, scope))
