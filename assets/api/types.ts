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
/** Les moteurs qui savent MESURER un plan (EXPLAIN ANALYZE) : la requête y est jouée pour de bon. ClickHouse ne garde que l’estimation. */
export const ENGINE_CAN_MEASURE: Record<Engine, boolean> = { mysql: true, mariadb: true, postgresql: true, clickhouse: false, redis: false }

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
  /** Vitess : les lectures vont aux tablets réplica (`@replica`). Absent : non. */
  readReplica?: boolean
  /** Rebond SSH : jamais la clé privée, seulement qu'il y en a une. */
  transport?: JumpParams
}

export interface JumpParams {
  type: 'ssh'
  host: string
  port: number
  username: string
  hasPrivateKey: boolean
  /** Empreinte de la clé d'hôte que l'utilisateur a confirmée, nulle tant qu'il ne l'a pas fait. */
  hostKeyFingerprint: string | null
}

export interface JumpInput {
  type: 'ssh'
  host: string
  port?: number
  username: string
  /** Absente à la modification : la clé enregistrée est conservée. */
  privateKey?: string
  /** Absente : celle du même bastion est conservée. */
  hostKeyFingerprint?: string
}

/** Ce que le bastion publie de sa clé d'hôte, à faire confirmer par l'utilisateur. */
export interface HostKey {
  fingerprint: string
  keyType: string
}

/** Ce qu'un essai de connexion MySQL apprend d'un vtgate : un réplica en service, aucun, pas un Vitess, ou inconnu. */
export type ReplicaAvailability = 'available' | 'missing' | 'not_vitess' | 'unknown'

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
  /** Absent : rien ne change (à la modification, le rebond enregistré est conservé) ; `null` : on le retire. */
  transport?: JumpInput | null
  /** Absent à la modification : le choix enregistré est conservé ; `false` : on le retire. MySQL seulement (Vitess). */
  readReplica?: boolean
}

export type TableKind = 'table' | 'view' | 'materialized_view' | 'sequence'

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
  /** Particularité que l’éditeur ne sait pas redire (colonne générée…) : la colonne ne se redéfinit pas. */
  lock?: string | null
}

export interface IndexInfo {
  name: string
  columns: string[]
  unique: boolean
  primary: boolean
  /** Place sur disque de l’index, quand le moteur la donne. */
  sizeBytes?: number | null
  /** Particularité que l’éditeur ne sait pas redire (index invisible…) : l’index ne se recrée pas. */
  lock?: string | null
}

export interface ForeignKeyInfo {
  name: string
  columns: string[]
  referencedTable: string
  referencedColumns: string[]
  onUpdate: string
  onDelete: string
}

export type PartitionMethod = 'range' | 'list' | 'hash' | 'key'

export interface SubpartitionInfo {
  name: string
  estimatedRows: number | null
  dataBytes: number | null
}

export interface PartitionInfo {
  name: string
  /** Borne ou valeurs telles que le moteur les écrit (affichage seulement). */
  bound: string | null
  /** MAXVALUE ou DEFAULT : reçoit tout ce que les autres refusent. */
  catchAll: boolean
  estimatedRows: number | null
  dataBytes: number | null
  subpartitions: SubpartitionInfo[]
}

/** Le découpage d’une table en partitions (lecture seule). */
export interface PartitioningInfo {
  method: PartitionMethod
  expression: string
  subMethod: PartitionMethod | null
  subExpression: string | null
  estimatedRows: number | null
  dataBytes: number | null
  /** Nombre réel de partitions ; la liste n’en montre que 200 au plus quand `truncated` est vrai (totaux alors inconnus). */
  partitionCount: number
  truncated: boolean
  partitions: PartitionInfo[]
}

export interface TableDetail {
  name: string
  /** Empreinte de la structure : renvoyée à l’API pour refuser un brouillon bâti sur une table qui a changé. */
  fingerprint: string
  kind: TableKind
  /** Table à versionnement système (MariaDB) : elle garde l’historique de ses lignes. */
  versioned?: boolean
  estimatedRows: number | null
  viewDefinition: string | null
  /** Place sur disque des données et de tous les index, quand le moteur la donne. */
  dataBytes?: number | null
  indexBytes?: number | null
  columns: ColumnInfo[]
  indexes: IndexInfo[]
  foreignKeys: ForeignKeyInfo[]
  /** Partitions de la table (lecture seule) ; nul ou absent sans partition. */
  partitioning?: PartitioningInfo | null
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
  /** Lit la liste des bases et des schémas du serveur (aucune donnée de table). */
  readSchema: boolean
  /** Exporte un résultat en CSV / JSON. */
  export: boolean
  /** Écrit sans préparation préalable (choix du propriétaire) ; sinon prepare puis execute. */
  singleStep: boolean
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
  readSchema?: boolean
  export?: boolean
  singleStep?: boolean
}

/** Pourquoi une source de supervision se lit ou non : « vide » n'est jamais un état. */
/** `error` : la vérification a échoué pour une cause imprévue (`reason: check_failed`) ; on ne sait pas, et on le dit. */
export type DiagnosticAvailability = 'available' | 'unsupported' | 'permission_denied' | 'disabled' | 'error'

/** Un tablet que vtgate sait viser (Vitess) : on lit un tablet à la fois, jamais « le cluster ». */
export interface VitessTablet {
  alias: string
  cell: string
  keyspace: string
  shard: string
  type: string
  serving: boolean
}

/** Un tablet que le serveur a écarté de la liste, et pourquoi (jamais son alias ni une valeur du serveur). */
export interface SkippedTablet {
  reason: 'type_not_targetable' | 'unexpected_name' | 'unreadable'
  /** Le type s'il fait partie de ceux que Vitess connaît (BACKUP, RESTORE, DRAINED…), sinon null. */
  type: string | null
}

export interface TabletListing {
  tablets: VitessTablet[]
  skipped: SkippedTablet[]
}

/** Un tablet lu pour la comparaison : `failure` quand il n'a pas pu l'être, jamais un tablet sain par défaut. */
export interface TabletReading extends VitessTablet {
  failure: string | null
  replication: ReplicationReport | null
  variablesStatus: DiagnosticStatus | null
  /** Variables modifiées par rapport au défaut ; nul si la source est illisible. */
  changedVariables: number | null
}

/** Une variable dont la valeur diffère d'un tablet à l'autre ; `null` = au défaut sur ce tablet. */
export interface TabletVariableDifference {
  name: string
  unit: string | null
  values: Record<string, string | null>
}

export interface TabletComparison {
  tablets: TabletReading[]
  differences: TabletVariableDifference[]
  /** Variables masquées (secrets), écartées sans être montrées. */
  masked: number
  /** Tous les tablets ne sont pas lus (au plus huit). */
  truncated: boolean
}

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

export type ReplicationRole = 'none' | 'source' | 'replica' | 'peer'
export type ThreadState = 'running' | 'connecting' | 'stopped'

/** Dernière erreur d'un fil de réplication : texte neutralisé par le serveur (ni hôte, ni compte, ni mot de passe). */
export interface ReplicationError {
  code: number | null
  message: string
}

export interface ReplicationChannel {
  name: string
  receiver: ThreadState
  applier: ThreadState
  /** Retard annoncé par le moteur (trompeur : voir `pendingApply`). */
  lagSeconds: number | null
  received: string | null
  applied: string | null
  /** Des transactions reçues ne sont pas encore appliquées (null : inconnu). */
  pendingApply: boolean | null
  receiverError: ReplicationError | null
  applierError: ReplicationError | null
}

export interface ReplicationAlert {
  code: string
  severity: 'warning' | 'critical'
  channel: string
}

/** PostgreSQL : un slot de réplication ; inactif ou en retard, il retient du WAL sur le disque du primaire. */
export interface ReplicationSlot {
  name: string
  type: string
  active: boolean
  temporary: boolean
  retainedBytes: number | null
  walStatus: string | null
  safeWalSize: number | null
}

/** Un réplica vu depuis sa source : un rang, jamais d'adresse. Retards en secondes. */
export interface ReplicaLink {
  rank: number
  state: string | null
  connected: boolean
  syncState: string | null
  writeLagSeconds: number | null
  flushLagSeconds: number | null
  replayLagSeconds: number | null
  behindBytes: number | null
}

export type SemiSyncMode = 'not_loaded' | 'off' | 'active' | 'fallen_back'

export interface ReplicatedTable {
  database: string
  table: string
  readonly: boolean
  sessionExpired: boolean
  absoluteDelaySeconds: number
  queueSize: number
  insertsInQueue: number
  lostParts: number
}

/** Les sections propres au moteur sont absentes quand le moteur n'a pas la notion ; chacune a son état. */
export interface ReplicationReport {
  status: DiagnosticStatus
  role: ReplicationRole
  replicaCount: number | null
  settings: { readOnly: boolean | null; superReadOnly: boolean | null; binlogFormat: string | null; gtid: boolean | null }
  channels: ReplicationChannel[]
  alerts: ReplicationAlert[]
  thresholds: { warning: number; critical: number }
  slots?: { status: DiagnosticStatus; slots: ReplicationSlot[] }
  replicas?: { status: DiagnosticStatus; replicas: ReplicaLink[] }
  binlog?: {
    status: DiagnosticStatus
    enabled: boolean | null
    fileCount: number | null
    totalBytes: number | null
    /** 0 : rien n'expire jamais. */
    expireSeconds: number | null
    gtidExecuted: string | null
    gtidPurged: string | null
  }
  semiSync?: { status: DiagnosticStatus; source: SemiSyncMode; replica: SemiSyncMode; clients: number | null }
  replicatedTables?: { status: DiagnosticStatus; tables: ReplicatedTable[]; truncated: boolean }
  keyValue?: { linkStatus: string | null; lastIoSecondsAgo: number | null; syncInProgress: boolean | null; backlogSize: number | null; masterReplOffset: number | null }
  limits?: { slotRetainedWarningBytes: number; slotRetainedCriticalBytes: number; tableQueueWarning: number }
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

/** Une constatation de l'audit de configuration : l'explication et la suggestion sont des textes fixes du serveur, la suggestion est à LIRE. */
export interface ConfigFinding {
  rule: string
  severity: 'high' | 'medium' | 'low'
  variable: string
  value: string
  recommended: string
  suggestion: string | null
  explanation: string
}

export interface ConfigAuditReport {
  status: DiagnosticStatus
  engine: string
  /** Règles dont les variables étaient lisibles, sur le total du moteur. */
  evaluated: number
  rulesTotal: number
  findings: ConfigFinding[]
}

/** Une variable qui n'a pas la même valeur sur deux serveurs ; jamais une variable masquée. */
export interface VariableDifference {
  name: string
  presentA: boolean
  valueA: string | null
  changedA: boolean
  presentB: boolean
  valueB: string | null
  changedB: boolean
  unit: string | null
  /** Les deux textes montrés sont identiques (coupés à l’affichage) : la différence est plus loin. */
  beyondDisplay: boolean
}

export interface VariableComparison {
  status: DiagnosticStatus
  unavailableSide: 'a' | 'b' | null
  /** Variables écartées parce que leur valeur est cachée d'un côté au moins. */
  masked: number
  truncated: boolean
  differences: VariableDifference[]
}

/** Un compte (ou un rôle) d'un serveur SQL. Jamais d'empreinte de mot de passe : `passwordSet` n'est qu'un booléen. */
export interface AccountEntry {
  user: string
  host: string
  name: string
  plugin: string
  passwordSet: boolean
  passwordExpired: boolean
  locked: boolean
  tls: 'none' | 'any' | 'x509' | 'specified'
  isRole: boolean
  /** Le compte que TerrilDB utilise pour cette connexion. */
  current: boolean
  roles: string[]
  defaultRoles: string[]
  globalPrivileges: string[]
  databasePrivileges: Record<string, string[]>
  grantOptionOn: string[]
  limits: Record<string, number>
}

/** Une constatation de l'audit de sécurité des comptes ; `rule` est un code stable. */
export interface SecurityFinding {
  rule: string
  severity: 'high' | 'medium' | 'low'
  account: string | null
  detail: string | null
  currentAccount: boolean
}

export interface AccountReport {
  status: DiagnosticStatus
  truncated: boolean
  /** Les droits lus sont incomplets : l'absence d'un droit n'est pas prouvée. */
  privilegesIncomplete: boolean
  activatesAllRoles: boolean
  accounts: AccountEntry[]
  findings: SecurityFinding[]
}

export interface ConnectionEncryption {
  status: DiagnosticStatus
  encrypted: boolean
  protocol: string | null
  cipher: string | null
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
  /** Plan mesuré seulement : lignes réellement rendues par exécution du nœud, temps d’une exécution, nombre d’exécutions. */
  actualRows?: number | null
  actualTimeMs?: number | null
  loops?: number | null
  sortsOnDisk?: boolean
  buffers?: { sharedHit: number; sharedRead: number; tempRead: number; tempWritten: number } | null
}

export interface PlanFindingInfo {
  rule: string
  relation: string | null
  rows: number | null
  condition: string | null
  actualRows?: number | null
  /** Une instruction SQL À LIRE : TerrilDB ne l’exécute jamais. */
  suggestion?: string | null
}

export interface PlanResult {
  plan: { totalCost: number | null; root: PlanNodeInfo; measured?: boolean; executionTimeMs?: number | null }
  findings: PlanFindingInfo[]
  /** Présent quand une mesure a été demandée : `measured`, ou `unsupported` (le moteur ne mesure pas, le plan est estimé). */
  measure?: { status: 'measured' | 'unsupported'; reason?: string }
}

/** Les règles de conception de l'audit (voir `designWording.ts`). */
export type DesignRule = 'legacy_character_set' | 'implicit_foreign_key' | 'collation_mismatch' | 'twin_table' | 'copy_without_indexes' | 'timestamp_deadline' | 'date_as_text'

export interface LintFindingInfo {
  rule: string
  severity: 'warning' | 'info'
  message: string
  suggestion: string | null
  columns: string[]
}

export interface LintResult {
  findings: LintFindingInfo[]
}

export interface SchemaFinding {
  rule: 'missing_primary_key' | 'foreign_key_without_index' | 'redundant_index' | 'foreign_key_type_mismatch' | 'text_primary_key' | 'float_amount' | 'unused_index' | 'counter_near_limit' | OperationsRule | DesignRule | 'partition_range_exhausted' | 'too_many_partitions'
  severity: 'critical' | 'warning' | 'info'
  table: string
  columns: string[]
  index: string | null
  coveredBy: string | null
  suggestion: string | null
  detail: string | null
}

/** Les règles d'exploitation : lues dans les compteurs et le catalogue du moteur. */
export type OperationsRule = 'stale_statistics' | 'dead_tuples' | 'reclaimable_space' | 'orphan_definer'

/** Une règle d'exploitation qui n'a pas pu juger : une raison, jamais un constat. */
export interface AuditCheck {
  rule: 'unused_index' | OperationsRule
  state: 'unavailable' | 'too_short'
  reason: string | null
  detail: string | null
}

export interface SchemaAudit {
  tablesAudited: number
  truncated: boolean
  findings: SchemaFinding[]
  checks: AuditCheck[]
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

/** Un compte tel que le voit un administrateur : rien de chiffré, pas d’e-mail. */
export interface AccountInfo {
  id: string
  username: string
  role: 'admin' | 'member'
  totpEnabled: boolean
  lastLoginAt: string | null
  disabled: boolean
  current: boolean
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
  /** Seulement dans l’aperçu : l’algorithme probable du moteur d’après une table de règles vérifiée (rien n’est essayé). */
  algorithm?: AlterAlgorithmName
  /** Bloque les écritures pendant l’instruction ; nul : non vérifié. */
  blocksWrites?: boolean | null
  /** Recopie toute la table ; nul : non vérifié. */
  copiesTable?: boolean | null
  /** Place disque en plus pour une copie ou une réécriture (données + index). */
  estimatedExtraBytes?: number | null
  /** Pourquoi l’algorithme est inconnu (code stable), nul sinon. */
  algorithmReason?: string | null
}

/** Ce qu'un plan fait perdre au-delà de son SQL (historique d'une table versionnée, valeurs retirées d'un ENUM/SET). */
export interface StructureLoss {
  code: string
  subject: string
  values: string[]
}

export type AlterAlgorithmName = 'instant' | 'inplace' | 'copy' | 'rewrite' | 'unknown'

export interface StructurePlan {
  statements: StructureStatement[]
  losses?: StructureLoss[]
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

/** Ce que la taille estimée de la table demande avant de la modifier (seuils d’installation, 0 = désactivé). */
export interface BigTableAssessment {
  level: 'none' | 'warning' | 'confirm_name'
  /** Estimation du moteur, nulle s’il n’en donne pas. */
  estimatedRows: number | null
  warnAt: number
  confirmAt: number
}

export interface StructurePreview extends StructurePlan {
  bigTable: BigTableAssessment
  fingerprint: string
  /** La table a changé depuis qu’on l’a chargée. */
  stale: boolean
  /** Nul quand le moteur n'a pas de diagnostic. */
  lockCheck?: StructureLockCheck | null
  /** Vitess : la table nourrit un flux (Materialize, MoveTables) qui ne suivra pas ce changement. */
  feedsStream?: boolean
  /** Vitess : les flux concernés, avec le workflow et les tables qu’ils remplissent. */
  streams?: StreamHit[]
}

/** Vitess : un flux VReplication (Materialize, MoveTables) dont la table touchée est la source. */
export interface StreamHit {
  /** Vide quand le serveur ne le dit pas. */
  workflow: string
  table: string
  /** Vide quand le flux se définit par une expression. */
  targets: string[]
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

/** Un jeton d’API vu par un administrateur : métadonnées seulement, jamais le texte du jeton ni sa clé. */
export interface AdminTokenInfo {
  id: string
  name: string
  owner: { id: string; username: string; disabled: boolean }
  /** Un nom de droit par entrée (writeData, diagnostic…) ; un droit ajouté côté serveur arrive ici sans autre changement. */
  permissions: Record<string, boolean>
  allConnections: boolean
  connectionCount: number
  status: 'active' | 'expired'
  createdAt: string
  expiresAt: string | null
  lastUsedAt: string | null
}

export interface AdminJournalEvent {
  id: string
  occurredAt: string
  action: 'account.disabled' | 'account.enabled' | 'admin.token_revoked' | 'admin.administrator_token_revoked'
  actor: { id: string; username: string | null } | null
  subjectId: string | null
  /** Propriétaire du jeton révoqué (identifiant de compte). */
  owner: string | null
}
