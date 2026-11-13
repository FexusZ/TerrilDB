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
export const ENGINE_HAS_PLAN: Record<Engine, boolean> = { mysql: true, mariadb: true, postgresql: true, clickhouse: true, redis: false }

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

/** Couleur de repère d'une connexion : un nom, que l'interface traduit en jeton de design (thème clair et sombre). */
export type ConnectionColor = 'red' | 'orange' | 'yellow' | 'green' | 'teal' | 'blue' | 'violet' | 'pink'

export const CONNECTION_COLORS: { value: ConnectionColor; label: string }[] = [
  { value: 'red', label: 'Rouge' },
  { value: 'orange', label: 'Orange' },
  { value: 'yellow', label: 'Jaune' },
  { value: 'green', label: 'Vert' },
  { value: 'teal', label: 'Turquoise' },
  { value: 'blue', label: 'Bleu' },
  { value: 'violet', label: 'Violet' },
  { value: 'pink', label: 'Rose' },
]

export interface ConnectionSummary {
  id: string
  name: string
  engine: Engine
  color?: ConnectionColor | null
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
  /** Absente : aucune couleur (à la modification, la couleur est retirée). */
  color?: ConnectionColor
  /** Absent à la modification : le mot de passe enregistré est conservé. */
  password?: string
}

export type TableKind = 'table' | 'view' | 'materialized_view'

export interface DatabaseInfo {
  name: string
  system: boolean
}

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
  /** Place sur disque de l’index, quand le moteur la donne. */
  sizeBytes?: number | null
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
  /** Empreinte de la structure : renvoyée à l’API pour refuser un brouillon bâti sur une table qui a changé. */
  fingerprint: string
  kind: TableKind
  estimatedRows: number | null
  viewDefinition: string | null
  /** Place sur disque des données et de tous les index, quand le moteur la donne. */
  dataBytes?: number | null
  indexBytes?: number | null
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
  writeData: boolean
  writeSchema: boolean
  createConnections: boolean
  /** Lit la supervision du serveur (sessions…), sans rien écrire. */
  diagnostic: boolean
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
  writeData?: boolean
  writeSchema?: boolean
  createConnections?: boolean
  diagnostic?: boolean
}

/** Pourquoi une source de supervision se lit ou non : « vide » n'est jamais un état. */
/** `error` : la vérification a échoué pour une cause imprévue (`reason: check_failed`) ; on ne sait pas, et on le dit. */
export type DiagnosticAvailability = 'available' | 'unsupported' | 'permission_denied' | 'disabled' | 'error'

export interface DiagnosticStatus {
  availability: DiagnosticAvailability
  /** Code stable (`insufficient_privilege`, `not_supported`…), jamais le message du moteur. */
  reason: string | null
  /** Le droit manquant, pour `permission_denied`. */
  privilege: string | null
}

export interface ServerSession {
  id: string
  user: string | null
  client: string | null
  database: string | null
  state: string | null
  seconds: number | null
  /** Début de la requête en cours, littéraux masqués par `?` puis raccourci par le serveur. */
  query: string | null
  /** `redacted` : le texte n'a pu être masqué sans risque, il n'est pas rendu. */
  queryReason: 'redacted' | null
  /** La session de TerrilDB lui-même. */
  current: boolean
}

export interface SessionReport {
  status: DiagnosticStatus
  truncated: boolean
  sessions: ServerSession[]
}

export interface OpenTransaction {
  /** Identifiant de la session qui la tient. */
  session: string
  user: string | null
  state: string | null
  /** Âge de la transaction, en secondes. */
  seconds: number
  rowsLocked: number | null
  rowsModified: number | null
  /** La session n'exécute rien : la transaction est oubliée (elle garde ses verrous). */
  sleeping: boolean
  query: string | null
  queryReason: 'redacted' | null
}

/** Une session de l'arbre de blocage ; durée, mode et objet sont ceux de SON attente vis-à-vis de son parent (nuls pour une racine). */
export interface BlockingNode {
  id: string
  user: string | null
  query: string | null
  queryReason: 'redacted' | null
  seconds: number | null
  kind: 'row' | 'metadata' | null
  mode: string | null
  object: string | null
  blocked: BlockingNode[]
}

export interface LockReport {
  status: DiagnosticStatus
  truncated: boolean
  transactions: OpenTransaction[]
  blocking: BlockingNode[]
  /** L'arbre a été borné (nombre de sessions ou profondeur) : des sessions manquent. */
  blockingTruncated: boolean
}

export type DigestOrder = 'total_time' | 'mean_time' | 'calls'

/** Une famille de requêtes du serveur, regroupées par empreinte : le texte est normalisé par le moteur et ses littéraux masqués. */
export interface StatementDigest {
  text: string | null
  /** `redacted` : le texte n'a pu être masqué sans risque, il n'est pas rendu. */
  textReason: 'redacted' | null
  database: string | null
  calls: number
  /** Durées en millisecondes, quelle que soit l'unité de la source. */
  totalMs: number
  meanMs: number
  maxMs: number | null
  rowsExamined: number | null
  rowsReturned: number | null
  /** Lignes examinées pour une ligne renvoyée ; nul si la source ne compte pas les lignes lues. */
  ratio: number | null
  noIndexUsed: number | null
  tmpDiskTables: number | null
  sortMergePasses: number | null
  firstSeen: string | null
  lastSeen: string | null
}

export interface DigestReport {
  status: DiagnosticStatus
  order: DigestOrder
  /** Fenêtre lue, en heures (ClickHouse) ; nulle quand la source cumule depuis sa remise à zéro. */
  windowHours: number | null
  digests: StatementDigest[]
}

export interface ServerVariable {
  name: string
  /** Nul pour un secret (`masked`) ou une valeur que le compte ne peut pas lire. */
  value: string | null
  default: string | null
  /** Diffère du défaut du moteur (ou a une autre origine que la compilation quand il ne donne pas de défaut). */
  changed: boolean
  /** Le nom évoque un secret : la valeur n'est jamais servie. */
  masked: boolean
  source: string | null
  restart: 'required' | 'pending' | null
  scope: 'global' | 'session' | 'server' | null
  unit: string | null
}

export interface VariableReport {
  status: DiagnosticStatus
  truncated: boolean
  variables: ServerVariable[]
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
  rule: 'missing_primary_key' | 'foreign_key_without_index' | 'redundant_index' | 'foreign_key_type_mismatch'
  severity: 'warning' | 'info'
  table: string
  columns: string[]
  index: string | null
  coveredBy: string | null
  suggestion: string | null
  detail: string | null
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

/** Une instruction de structure que le serveur a générée, avec ce qu’elle fait et si elle détruit quelque chose. */
export interface StructureStatement {
  sql: string
  operation: string
  subject: string
  destructive: boolean
}

export interface StructurePlan {
  statements: StructureStatement[]
  /** Tout ou rien (une transaction) ; sinon chaque instruction est validée seule. */
  transactional: boolean
  destructive: boolean
}

/** Une session qui tient la table : un ALTER attendrait sa fin. Jamais le texte de sa requête. */
export interface TableHolder {
  session: string
  user: string | null
  /** Âge de sa transaction, quand le moteur le dit. */
  seconds: number | null
  sleeping: boolean
  mode: string | null
}

/** Pré-contrôle des verrous : un avertissement, jamais un blocage ; `status` dit si la source se lit. */
export interface StructureLockCheck {
  status: DiagnosticStatus
  holders: TableHolder[]
}

export interface StructurePreview extends StructurePlan {
  fingerprint: string
  /** La table a changé depuis qu’on l’a chargée. */
  stale: boolean
  /** Nul quand le moteur n'a pas de diagnostic. */
  lockCheck?: StructureLockCheck | null
}

export type ReferentialActionName = 'no_action' | 'restrict' | 'cascade' | 'set_null' | 'set_default'

/** Ce que l’API de structure reçoit : la table telle qu’on la veut, jamais du SQL. */
export interface StructurePayload {
  name: string
  columns: { originalName: string | null; name: string; type: string; nullable: boolean; default: string | null; autoIncrement: boolean }[]
  indexes: { originalName: string | null; name: string; columns: string[]; unique: boolean; primary: boolean; kind: string | null; granularity: number | null }[]
  foreignKeys: { originalName: string | null; name: string; columns: string[]; referencedTable: string; referencedColumns: string[]; onUpdate: ReferentialActionName; onDelete: ReferentialActionName }[]
}

/** Ce que l’éditeur de structure sait faire selon le moteur (le serveur refuse le reste). */
export interface StructureFeatures {
  primaryKey: boolean
  foreignKeys: boolean
  autoIncrement: boolean
  uniqueIndex: boolean
  /** Index de saut (minmax, bloom_filter, set) au lieu d’index classiques. */
  skipIndex: boolean
  renameIndex: boolean
}

export const ENGINE_STRUCTURE: Record<Engine, StructureFeatures> = {
  mysql: { primaryKey: true, foreignKeys: true, autoIncrement: true, uniqueIndex: true, skipIndex: false, renameIndex: true },
  mariadb: { primaryKey: true, foreignKeys: true, autoIncrement: true, uniqueIndex: true, skipIndex: false, renameIndex: true },
  postgresql: { primaryKey: true, foreignKeys: true, autoIncrement: true, uniqueIndex: true, skipIndex: false, renameIndex: true },
  clickhouse: { primaryKey: false, foreignKeys: false, autoIncrement: false, uniqueIndex: false, skipIndex: true, renameIndex: false },
  redis: { primaryKey: false, foreignKeys: false, autoIncrement: false, uniqueIndex: false, skipIndex: false, renameIndex: false },
}

/** Types proposés en suggestion ; la saisie reste libre (le serveur n’en vérifie que la forme). */
export const COLUMN_TYPES: Record<Engine, string[]> = {
  mysql: ['int', 'bigint', 'smallint', 'tinyint(1)', 'decimal(10,2)', 'float', 'double', 'varchar(255)', 'char(36)', 'text', 'longtext', 'json', 'date', 'datetime', 'timestamp', 'blob'],
  mariadb: ['int', 'bigint', 'smallint', 'tinyint(1)', 'decimal(10,2)', 'float', 'double', 'varchar(255)', 'char(36)', 'text', 'longtext', 'json', 'date', 'datetime', 'timestamp', 'blob'],
  postgresql: ['integer', 'bigint', 'smallint', 'numeric(10,2)', 'real', 'double precision', 'boolean', 'character varying(255)', 'text', 'uuid', 'jsonb', 'date', 'timestamp without time zone', 'timestamp with time zone', 'bytea'],
  clickhouse: ['UInt8', 'UInt32', 'UInt64', 'Int32', 'Int64', 'Float64', 'Decimal(18, 4)', 'String', 'UUID', 'Date', 'DateTime', 'DateTime64(3)', 'Bool', 'Array(String)', 'LowCardinality(String)'],
  redis: [],
}
