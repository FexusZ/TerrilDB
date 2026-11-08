import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { ApiError, api, apiDownload } from '../api/client'
import { messageFor } from '../api/errors'
import { saveFile } from '../utils/download'
import type { DatabaseInfo, HistoryEntry, KeyEntry, KeyPage, PlanResult, QueryMode, QueryResult, TableDetail, TableSummary } from '../api/types'
import { useConnectionsStore } from './connections'

export interface QueryTab {
  id: number
  title: string
  sql: string
  mode: QueryMode
  running: boolean
  result: QueryResult | null
  error: string | null
  /** Motif de la confirmation demandée par le garde, en attendant la décision de l'utilisateur. */
  pendingConfirmation: string | null
  exporting: boolean
  /** Ce que la zone de résultat montre : le résultat de l'exécution, ou le plan estimé. */
  view: 'result' | 'plan'
  plan: PlanResult | null
  planning: boolean
  /** Précision à montrer après un export (fichier coupé à la limite de lignes). */
  exportNotice: string | null
  /** Table dont cet onglet montre les données (vue « Données »), nul pour un onglet de console. */
  table: string | null
}

export const useWorkspaceStore = defineStore('workspace', () => {
  const connectionId = ref<string | null>(null)
  const tables = ref<TableSummary[]>([])
  const tablesError = ref<string | null>(null)
  // Les tables arrivent cent par cent (curseur = nom de la dernière) ; la recherche est faite par le moteur.
  const tablesNext = ref<string | null>(null)
  const tablesTotal = ref<number | null>(null)
  const tablesLoading = ref(false)
  const tablesSearch = ref('')
  let tablesRequest = 0
  // La base explorée : nulle = celle de la connexion. Les autres bases du serveur se choisissent à la demande.
  const database = ref<string | null>(null)
  const databases = ref<DatabaseInfo[]>([])
  const databasesError = ref<string | null>(null)
  const tablesHasMore = computed(() => tablesNext.value !== null)
  /** La base que la connexion nomme elle-même ; null quand elle n'en nomme aucune. */
  const defaultDatabase = ref<string | null>(null)
  /** PostgreSQL : le schéma exploré (null : le schéma courant) et ceux de la base. */
  const schema = ref<string | null>(null)
  const schemas = ref<DatabaseInfo[]>([])
  /** Les noms de TOUTES les tables de la base explorée, pour l'autocomplétion de l'éditeur (le panneau, lui, est paginé). */
  const completionTables = ref<string[]>([])
  let completionLoaded = ''
  // Une base clé-valeur (Redis) se parcourt par clés : même espace de travail, autre arborescence.
  const isKeyValue = ref(false)
  const keys = ref<KeyEntry[]>([])
  const keysCursor = ref<string | null>(null)
  const keysTotal = ref(0)
  const keysPattern = ref('*')
  const keysLoading = ref(false)
  const keysError = ref<string | null>(null)
  let keysRequest = 0
  const tabs = ref<QueryTab[]>([])
  const activeId = ref(0)
  const history = ref<HistoryEntry[]>([])
  const historyNext = ref<string | null>(null)
  const historyLoaded = ref(false)
  const historyLoading = ref(false)
  const historyError = ref<string | null>(null)
  const described = new Map<string, TableDetail>()
  let nextId = 1
  let runCounter = 0
  const latestRun = new Map<number, number>()

  const activeTab = computed<QueryTab>(() => tabs.value.find((tab) => tab.id === activeId.value) ?? tabs.value[0] ?? blankTab())

  function blankTab(sql = ''): QueryTab {
    const id = nextId++
    return { id, title: `Requête ${String(id)}`, sql, mode: 'read_only', running: false, result: null, error: null, pendingConfirmation: null, exporting: false, exportNotice: null, view: 'result', plan: null, planning: false, table: null }
  }

  function tab(id: number): QueryTab | undefined {
    return tabs.value.find((candidate) => candidate.id === id)
  }

  function reset(): void {
    nextId = 1
    tabs.value = [blankTab()]
    activeId.value = tabs.value[0]?.id ?? 0
    described.clear()
    latestRun.clear()
    history.value = []
    historyNext.value = null
    historyLoaded.value = false
    historyError.value = null
  }

  async function open(id: string): Promise<void> {
    if (connectionId.value === id) {
      return
    }
    connectionId.value = id
    tables.value = []
    tablesError.value = null
    tablesNext.value = null
    tablesTotal.value = null
    tablesSearch.value = ''
    database.value = null
    databases.value = []
    databasesError.value = null
    defaultDatabase.value = null
    schema.value = null
    schemas.value = []
    completionTables.value = []
    completionLoaded = ''
    keys.value = []
    keysCursor.value = null
    keysTotal.value = 0
    keysPattern.value = '*'
    keysError.value = null
    isKeyValue.value = useConnectionsStore().byId(id)?.engine === 'redis'
    reset()

    if (isKeyValue.value) {
      await loadKeys(false)
      return
    }

    await loadTables(false)
  }

  function tablesUrl(cursor: string | null): string {
    const params = new URLSearchParams({ limit: '100' })
    if (database.value !== null) {
      params.set('database', database.value)
    }
    if (schema.value !== null) {
      params.set('schema', schema.value)
    }
    if (cursor !== null) {
      params.set('cursor', cursor)
    }
    if (tablesSearch.value !== '') {
      params.set('q', tablesSearch.value)
    }

    return `/web/connections/${connectionId.value ?? ''}/tables?${params.toString()}`
  }

  /** Une page de tables : la première remplace la liste, la suivante s'y ajoute. Une réponse périmée est ignorée. */
  async function loadTables(append: boolean): Promise<void> {
    const id = connectionId.value
    const cursor = append ? tablesNext.value : null
    if (id === null || (append && cursor === null)) {
      return
    }
    const request = ++tablesRequest
    tablesLoading.value = true
    tablesError.value = null
    try {
      const page = await api<{ tables: TableSummary[]; nextCursor?: string | null; total?: number | null }>('GET', tablesUrl(cursor))
      if (request !== tablesRequest || connectionId.value !== id) {
        return
      }
      tables.value = append ? [...tables.value, ...page.tables] : page.tables
      tablesNext.value = page.nextCursor ?? null
      if (!append) {
        tablesTotal.value = page.total ?? null
      }
    } catch (error) {
      if (request !== tablesRequest || connectionId.value !== id) {
        return
      }
      tablesError.value = messageFor(error)
      if (!append) {
        tables.value = []
        tablesNext.value = null
        tablesTotal.value = null
      }
    } finally {
      if (request === tablesRequest) {
        tablesLoading.value = false
      }
    }
  }

  async function loadMoreTables(): Promise<void> {
    if (tablesNext.value !== null && !tablesLoading.value) {
      await loadTables(true)
    }
  }

  /** Recherche faite par le moteur, depuis la première page. */
  async function searchTables(query: string): Promise<void> {
    tablesSearch.value = query.trim()
    await loadTables(false)
  }

  /** Les bases visibles sur le serveur, pour le sélecteur. Un moteur sans liste (Redis) n'est pas une erreur. */
  async function loadDatabases(): Promise<void> {
    if (connectionId.value === null || isKeyValue.value) {
      return
    }
    const id = connectionId.value
    databasesError.value = null
    try {
      const result = await api<{ default?: string | null; databases: DatabaseInfo[] } | undefined>('GET', `/web/connections/${id}/databases`)
      if (connectionId.value !== id) {
        return
      }
      databases.value = result?.databases ?? []
      defaultDatabase.value = result?.default ?? null
      const first = databases.value[0]
      // Une connexion sans base nommée ne montre rien d'elle-même : on ouvre la première base de la liste.
      if (result?.default === null && first !== undefined && database.value === null) {
        await selectDatabase(first.name)
      } else {
        await loadSchemas()
      }
    } catch (error) {
      if (connectionId.value !== id) {
        return
      }
      databases.value = []
      if (!(error instanceof ApiError && error.code === 'unsupported_operation')) {
        databasesError.value = messageFor(error)
      }
    }
  }

  /** Les schémas de la base explorée : PostgreSQL seulement, les autres moteurs n'ont pas ce niveau. */
  async function loadSchemas(): Promise<void> {
    const id = connectionId.value
    if (id === null || useConnectionsStore().byId(id)?.engine !== 'postgresql') {
      return
    }
    const name = database.value
    try {
      const result = await api<{ schemas: DatabaseInfo[] } | undefined>('GET', `/web/connections/${id}/schemas${scopeQuery(name, null)}`)
      if (connectionId.value === id && database.value === name) {
        schemas.value = result?.schemas ?? []
      }
    } catch (error) {
      if (connectionId.value === id && database.value === name) {
        schemas.value = []
        if (!(error instanceof ApiError && error.code === 'unsupported_operation')) {
          databasesError.value = messageFor(error)
        }
      }
    }
  }

  /** Explore une autre base du même serveur (null : celle de la connexion) : schéma oublié, tables rechargées, descriptions oubliées. */
  async function selectDatabase(name: string | null): Promise<void> {
    if (connectionId.value === null || name === database.value) {
      return
    }
    database.value = name
    schema.value = null
    schemas.value = []
    await reloadTables()
    await loadSchemas()
  }

  /** Explore un autre schéma de la base (null : le schéma courant). */
  async function selectSchema(name: string | null): Promise<void> {
    if (connectionId.value === null || name === schema.value) {
      return
    }
    schema.value = name
    await reloadTables()
  }

  /** Après un changement de base ou de schéma : descriptions et recherche oubliées, première page relue. */
  async function reloadTables(): Promise<void> {
    described.clear()
    tablesSearch.value = ''
    await loadTables(false)
  }

  /**
   * Lit la liste COMPLÈTE des noms de tables (route sans pagination) pour l'autocomplétion : une fois par connexion, base
   * et schéma. Un échec n'est pas une erreur pour l'utilisateur, la complétion se contente de ce que le panneau a chargé.
   */
  async function loadCompletion(): Promise<void> {
    const id = connectionId.value
    if (id === null || isKeyValue.value) {
      return
    }
    const key = `${id}|${database.value ?? ''}|${schema.value ?? ''}`
    if (key === completionLoaded) {
      return
    }
    completionLoaded = key
    const current = (): boolean => completionLoaded === key
    try {
      const result = await api<{ tables: TableSummary[] } | undefined>('GET', `/web/connections/${id}/tables${scopeQuery(database.value, schema.value)}`)
      if (current()) {
        completionTables.value = (result?.tables ?? []).map((table) => table.name)
      }
    } catch {
      if (current()) {
        completionLoaded = ''
        completionTables.value = []
      }
    }
  }

  function scopeQuery(databaseName: string | null, schemaName: string | null): string {
    const params = new URLSearchParams()
    if (databaseName !== null) {
      params.set('database', databaseName)
    }
    if (schemaName !== null) {
      params.set('schema', schemaName)
    }
    const text = params.toString()

    return text === '' ? '' : `?${text}`
  }

  function databaseQuery(): string {
    return scopeQuery(database.value, schema.value)
  }

  /** Charge une page de clés : la première (remplace la liste) ou la suivante (s'y ajoute). */
  async function loadKeys(append: boolean): Promise<void> {
    if (connectionId.value === null) {
      return
    }
    const request = ++keysRequest
    const query = new URLSearchParams({ pattern: keysPattern.value, count: '100' })
    if (append && keysCursor.value !== null) {
      query.set('cursor', keysCursor.value)
    }

    keysLoading.value = true
    keysError.value = null
    try {
      const page = await api<KeyPage>('GET', `/web/connections/${connectionId.value}/keys?${query.toString()}`)
      if (request !== keysRequest) {
        return
      }
      keys.value = append ? [...keys.value, ...page.keys] : page.keys
      keysCursor.value = page.nextCursor
      keysTotal.value = page.total
    } catch (error) {
      if (request === keysRequest) {
        keysError.value = messageFor(error)
        if (!append) {
          keys.value = []
          keysCursor.value = null
        }
      }
    } finally {
      if (request === keysRequest) {
        keysLoading.value = false
      }
    }
  }

  async function loadMoreKeys(): Promise<void> {
    if (keysCursor.value !== null && !keysLoading.value) {
      await loadKeys(true)
    }
  }

  async function searchKeys(pattern: string): Promise<void> {
    keysPattern.value = pattern
    await loadKeys(false)
  }

  function close(): void {
    connectionId.value = null
    tables.value = []
    tablesNext.value = null
    tablesTotal.value = null
    keys.value = []
    isKeyValue.value = false
    tabs.value = []
  }

  function addTab(sql = ''): number {
    const created = blankTab(sql)
    // Numéroté selon le nombre d'onglets ouverts plutôt que selon l'identifiant interne.
    created.title = `Requête ${String(tabs.value.length + 1)}`
    tabs.value = [...tabs.value, created]
    activeId.value = created.id

    return created.id
  }

  /** L'onglet des données d'une table : créé à la première visite (avec `sql`), retrouvé ensuite tel que l'utilisateur l'a laissé. */
  function openTableData(name: string, sql: string): { id: number; created: boolean } {
    const existing = tabs.value.find((candidate) => candidate.table === name)
    if (existing !== undefined) {
      activeId.value = existing.id

      return { id: existing.id, created: false }
    }
    const id = addTab(sql)
    const created = tab(id)
    if (created) {
      created.table = name
      created.title = `Données · ${name}`
    }

    return { id, created: true }
  }

  function closeTab(id: number): void {
    const remaining = tabs.value.filter((candidate) => candidate.id !== id)
    if (remaining.length === 0) {
      reset()
      return
    }
    tabs.value = remaining
    if (activeId.value === id) {
      activeId.value = remaining[0]?.id ?? 0
    }
  }

  function select(id: number): void {
    activeId.value = id
  }

  function setSql(id: number, sql: string): void {
    const target = tab(id)
    if (target) {
      target.sql = sql
      target.pendingConfirmation = null
      target.plan = null
      target.view = 'result'
    }
  }

  function setMode(id: number, mode: QueryMode): void {
    const target = tab(id)
    if (target) {
      target.mode = mode
      target.pendingConfirmation = null
    }
  }

  async function run(id: number, confirmed = false): Promise<void> {
    const target = tab(id)
    if (!target || connectionId.value === null) {
      return
    }
    if (target.sql.trim() === '') {
      target.error = 'Saisissez une instruction.'
      return
    }

    const run = ++runCounter
    latestRun.set(id, run)
    target.running = true
    target.error = null
    target.pendingConfirmation = null
    target.view = 'result'

    try {
      const result = await api<QueryResult>('POST', `/web/connections/${connectionId.value}/query`, { sql: target.sql, mode: target.mode, confirmed, ...(database.value === null ? {} : { database: database.value }), ...(schema.value === null ? {} : { schema: schema.value }) })
      if (latestRun.get(id) === run) {
        target.result = result
      }
    } catch (error) {
      if (latestRun.get(id) !== run) {
        return
      }
      if (error instanceof ApiError && error.code === 'confirmation_required') {
        target.pendingConfirmation = typeof error.details.reason === 'string' ? error.details.reason : 'destructive_statement'
      } else {
        target.result = null
        target.error = messageFor(error)
      }
    } finally {
      if (latestRun.get(id) === run) {
        target.running = false
      }
    }

    // Une fois l'historique ouvert, il suit les exécutions sans que l'utilisateur ait à le recharger.
    if (historyLoaded.value && target.pendingConfirmation === null) {
      void fetchHistory()
    }
  }

  /** Demande le plan estimé de l'instruction de l'onglet : le moteur l'explique, il ne l'exécute pas. */
  async function planTab(id: number): Promise<void> {
    const target = tab(id)
    if (!target || connectionId.value === null) {
      return
    }
    if (target.sql.trim() === '') {
      target.error = 'Saisissez une instruction.'
      return
    }

    target.planning = true
    target.error = null
    try {
      target.plan = await api<PlanResult>('POST', `/web/connections/${connectionId.value}/plan`, { sql: target.sql })
      target.view = 'plan'
    } catch (error) {
      target.plan = null
      target.error = messageFor(error)
    } finally {
      target.planning = false
    }
  }

  /** Télécharge le résultat de l'instruction de l'onglet (relancée côté serveur, en lecture seule, avec une limite plus haute). */
  async function exportTab(id: number, format: 'csv' | 'json'): Promise<void> {
    const target = tab(id)
    if (!target || connectionId.value === null) {
      return
    }
    if (target.sql.trim() === '') {
      target.error = 'Saisissez une instruction.'
      return
    }

    target.exporting = true
    target.error = null
    target.exportNotice = null
    try {
      const file = await apiDownload(`/web/connections/${connectionId.value}/export`, { sql: target.sql, format })
      saveFile(file.blob, file.filename)
      if (file.truncated) {
        target.exportNotice = 'Fichier limité à 10 000 lignes : d’autres existent. Affinez la requête pour tout exporter.'
      }
    } catch (error) {
      target.error = messageFor(error)
    } finally {
      target.exporting = false
    }
  }

  function confirmPending(id: number): Promise<void> {
    return run(id, true)
  }

  function cancelPending(id: number): void {
    const target = tab(id)
    if (target) {
      target.pendingConfirmation = null
    }
  }

  async function fetchHistory(before?: string): Promise<void> {
    if (connectionId.value === null) {
      return
    }
    historyLoading.value = true
    historyError.value = null
    const params = new URLSearchParams({ limit: '50' })
    if (before !== undefined) {
      params.set('before', before)
    }
    try {
      const page = await api<{ entries: HistoryEntry[]; next: string | null }>('GET', `/web/connections/${connectionId.value}/history?${params.toString()}`)
      history.value = before === undefined ? page.entries : [...history.value, ...page.entries]
      historyNext.value = page.next
      historyLoaded.value = true
    } catch (error) {
      historyError.value = messageFor(error)
    } finally {
      historyLoading.value = false
    }
  }

  /** Recharge l'historique des requêtes de la connexion depuis le début. */
  function loadHistory(): Promise<void> {
    return fetchHistory()
  }

  async function moreHistory(): Promise<void> {
    if (historyNext.value !== null && !historyLoading.value) {
      await fetchHistory(historyNext.value)
    }
  }

  async function describe(name: string): Promise<TableDetail> {
    const known = described.get(name)
    if (known) {
      return known
    }
    const { table } = await api<{ table: TableDetail }>('GET', `/web/connections/${connectionId.value ?? ''}/tables/${encodeURIComponent(name)}${databaseQuery()}`)
    described.set(name, table)

    return table
  }

  const historyHasMore = computed(() => historyNext.value !== null)

  /** `?database=…&schema=…` de ce qu'on explore (vide si la connexion est lue telle quelle) : pour les pages qui lisent la base elle-même. */
  const scopeSuffix = (): string => databaseQuery()

  return { connectionId, scopeSuffix, tables, tablesError, tablesTotal, tablesHasMore, tablesLoading, tablesSearch, loadMoreTables, searchTables, reloadTables, database, databases, databasesError, defaultDatabase, schema, schemas, completionTables, loadCompletion, loadDatabases, loadSchemas, selectDatabase, selectSchema, isKeyValue, keys, keysCursor, keysTotal, keysPattern, keysLoading, keysError, loadMoreKeys, searchKeys, tabs, activeTab, history, historyLoaded, historyLoading, historyError, historyHasMore, loadHistory, moreHistory, open, close, addTab, openTableData, closeTab, select, setSql, setMode, run, planTab, exportTab, confirmPending, cancelPending, describe }
})
