import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { ApiError, api, apiDownload } from '../api/client'
import { messageFor } from '../api/errors'
import { saveFile } from '../utils/download'
import type { HistoryEntry, KeyEntry, KeyPage, PlanResult, QueryMode, QueryResult, TableDetail, TableSummary } from '../api/types'
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
}

export const useWorkspaceStore = defineStore('workspace', () => {
  const connectionId = ref<string | null>(null)
  const tables = ref<TableSummary[]>([])
  const tablesError = ref<string | null>(null)
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
    return { id, title: `Requête ${String(id)}`, sql, mode: 'read_only', running: false, result: null, error: null, pendingConfirmation: null, exporting: false, exportNotice: null, view: 'result', plan: null, planning: false }
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

    try {
      tables.value = (await api<{ tables: TableSummary[] }>('GET', `/web/connections/${id}/tables`)).tables
    } catch (error) {
      tablesError.value = messageFor(error)
    }
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
      const result = await api<QueryResult>('POST', `/web/connections/${connectionId.value}/query`, { sql: target.sql, mode: target.mode, confirmed })
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
    const { table } = await api<{ table: TableDetail }>('GET', `/web/connections/${connectionId.value ?? ''}/tables/${encodeURIComponent(name)}`)
    described.set(name, table)

    return table
  }

  const historyHasMore = computed(() => historyNext.value !== null)

  return { connectionId, tables, tablesError, isKeyValue, keys, keysCursor, keysTotal, keysPattern, keysLoading, keysError, loadMoreKeys, searchKeys, tabs, activeTab, history, historyLoaded, historyLoading, historyError, historyHasMore, loadHistory, moreHistory, open, close, addTab, closeTab, select, setSql, setMode, run, planTab, exportTab, confirmPending, cancelPending, describe }
})
