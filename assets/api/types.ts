export type Engine = 'mysql' | 'mariadb' | 'postgresql'

export const ENGINE_LABELS: Record<Engine, string> = {
  mysql: 'MySQL',
  mariadb: 'MariaDB',
  postgresql: 'PostgreSQL',
}

export const ENGINE_BADGES: Record<Engine, string> = { mysql: 'My', mariadb: 'Ma', postgresql: 'Pg' }
export const DEFAULT_PORTS: Record<Engine, number> = { mysql: 3306, mariadb: 3306, postgresql: 5432 }

export interface User {
  id: string
  username: string
  email: string
  totpEnabled: boolean
  recoveryCodesLeft: number
  createdAt: string
}

export interface ConnectionSummary {
  id: string
  name: string
  engine: Engine
  createdAt: string
  updatedAt: string
}

/** Paramètres d'une connexion enregistrée, sans le mot de passe (qui ne revient jamais du serveur). */
export interface ConnectionParams {
  host: string
  port: number
  database: string
  username: string
  hasPassword: boolean
}

export interface ConnectionInput {
  name: string
  engine: Engine
  host: string
  port?: number
  database: string
  username: string
  /** Absent à la modification : le mot de passe enregistré est conservé. */
  password?: string
}

export type TableKind = 'table' | 'view' | 'materialized_view'

export interface TableSummary {
  name: string
  kind: TableKind
  estimatedRows: number | null
}

export interface ColumnInfo {
  name: string
  type: string
  nullable: boolean
  default: string | null
  autoIncrement: boolean
  comment: string | null
}

export interface IndexInfo {
  name: string
  columns: string[]
  unique: boolean
  primary: boolean
}

export interface ForeignKeyInfo {
  name: string
  columns: string[]
  referencedTable: string
  referencedColumns: string[]
  onUpdate: string
  onDelete: string
}

export interface TableDetail {
  name: string
  kind: TableKind
  estimatedRows: number | null
  viewDefinition: string | null
  columns: ColumnInfo[]
  indexes: IndexInfo[]
  foreignKeys: ForeignKeyInfo[]
}

export type Cell = string | number | boolean | null
export type QueryMode = 'read_only' | 'read_write'

export interface QueryResult {
  columns: string[]
  rows: Cell[][]
  rowCount: number
  truncated: boolean
  affectedRows: number | null
  durationMs: number
}

export interface ApiTokenInfo {
  id: string
  name: string
  connectionIds: string[]
  createdAt: string
  expiresAt: string
  lastUsedAt: string | null
}

export interface ApiTokenInput {
  name: string
  connectionIds: string[]
  lifetimeDays: number
}
