export type Engine = 'mysql' | 'mariadb' | 'postgresql' | 'clickhouse' | 'redis'

export const ENGINE_LABELS: Record<Engine, string> = {
  mysql: 'MySQL',
  mariadb: 'MariaDB',
  postgresql: 'PostgreSQL',
  clickhouse: 'ClickHouse',
  redis: 'Redis',
}

export const ENGINE_BADGES: Record<Engine, string> = { mysql: 'My', mariadb: 'Ma', postgresql: 'Pg', clickhouse: 'Ch', redis: 'Rd' }
/** ClickHouse : port de l’interface HTTP, la seule que TerrilDB parle. */
export const DEFAULT_PORTS: Record<Engine, number> = { mysql: 3306, mariadb: 3306, postgresql: 5432, clickhouse: 8123, redis: 6379 }
/** Les moteurs dont TerrilDB sait lire un plan d’exécution. */
export const ENGINE_HAS_PLAN: Record<Engine, boolean> = { mysql: true, mariadb: true, postgresql: true, clickhouse: false, redis: false }

export interface User {
  id: string
  username: string
  email: string
  role: 'admin' | 'member'
  recoveryPhrase: boolean
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

export type KeyType = 'string' | 'hash' | 'list' | 'set' | 'zset' | 'stream' | 'other'

/** Une clé d’une base clé-valeur (Redis) : `ttl` en secondes, null sans expiration. */
export interface KeyEntry {
  name: string
  type: KeyType
  ttl: number | null
}

export interface KeyPage {
  keys: KeyEntry[]
  nextCursor: string | null
  total: number
}

/** Le contenu d’une clé, ramené à un tableau quel que soit son type. */
export interface KeyDetail extends KeyEntry {
  size: number | null
  columns: string[]
  rows: Cell[][]
  truncated: boolean
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
  allConnections: boolean
  createdAt: string
  /** Nul : le jeton n'expire jamais. */
  expiresAt: string | null
  lastUsedAt: string | null
}

export interface ApiTokenInput {
  name: string
  /** Absent quand `allConnections` est vrai : le serveur prend alors toutes les connexions du compte. */
  connectionIds?: string[]
  allConnections?: boolean
  lifetimeDays?: number
  /** Vrai : pas de date d'expiration, `lifetimeDays` est alors ignoré. */
  neverExpires?: boolean
}

export type AuditOutcome = 'success' | 'refused' | 'failed'
export type AuditActor = 'web' | 'api' | 'mcp' | 'system'

export interface AuditEntry {
  id: string
  occurredAt: string
  action: string
  category: string
  outcome: AuditOutcome
  actor: AuditActor
  ip: string | null
  subjectId: string | null
  tokenId: string | null
  details: Record<string, unknown>
}

/** Une exécution de requête dans l'historique d'une connexion. */
export interface HistoryEntry {
  id: string
  occurredAt: string
  actor: AuditActor
  outcome: AuditOutcome
  sql: string
  mode: string | null
  rowCount: number | null
  durationMs: number | null
  reason: string | null
}

export type PlanKind = 'full_scan' | 'index_full_scan' | 'index_scan' | 'join' | 'sort' | 'aggregate' | 'limit' | 'other'

export interface PlanNodeInfo {
  kind: PlanKind
  label: string
  relation: string | null
  index: string | null
  rows: number | null
  cost: number | null
  condition: string | null
  fullScan: boolean
  children: PlanNodeInfo[]
}

export interface PlanFindingInfo {
  rule: string
  relation: string | null
  rows: number | null
  condition: string | null
}

export interface PlanResult {
  plan: { totalCost: number | null; root: PlanNodeInfo }
  findings: PlanFindingInfo[]
}

export interface SchemaFinding {
  rule: 'missing_primary_key' | 'foreign_key_without_index' | 'redundant_index'
  severity: 'warning' | 'info'
  table: string
  columns: string[]
  index: string | null
  coveredBy: string | null
  suggestion: string | null
}

export interface SchemaAudit {
  tablesAudited: number
  truncated: boolean
  findings: SchemaFinding[]
}

export interface DiagramColumn {
  name: string
  type: string
  nullable: boolean
  primary: boolean
}

export interface DiagramTable {
  name: string
  columns: DiagramColumn[]
}

export interface DiagramRelation {
  name: string
  table: string
  columns: string[]
  referencedTable: string
  referencedColumns: string[]
}

export interface SchemaDiagram {
  truncated: boolean
  tables: DiagramTable[]
  relations: DiagramRelation[]
}

export interface InvitationInfo {
  id: string
  note: string
  status: 'pending' | 'used' | 'expired'
  createdAt: string
  expiresAt: string
  usedAt: string | null
  usedBy: string | null
}
