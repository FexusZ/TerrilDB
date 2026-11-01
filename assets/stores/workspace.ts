import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { ApiError, api } from '../api/client'
import { messageFor } from '../api/errors'
import type { QueryMode, QueryResult, TableDetail, TableSummary } from '../api/types'

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
}

export const useWorkspaceStore = defineStore('workspace', () => {
  const connectionId = ref<string | null>(null)
  const tables = ref<TableSummary[]>([])
  const tablesError = ref<string | null>(null)
  const tabs = ref<QueryTab[]>([])
  const activeId = ref(0)
  const described = new Map<string, TableDetail>()
  let nextId = 1
  let runCounter = 0
  const latestRun = new Map<number, number>()

  const activeTab = computed<QueryTab>(() => tabs.value.find((tab) => tab.id === activeId.value) ?? tabs.value[0] ?? blankTab())

  function blankTab(sql = ''): QueryTab {
    const id = nextId++
    return { id, title: `Requête ${String(id)}`, sql, mode: 'read_only', running: false, result: null, error: null, pendingConfirmation: null }
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
  }

  async function open(id: string): Promise<void> {
    if (connectionId.value === id) {
      return
    }
    connectionId.value = id
    tables.value = []
    tablesError.value = null
    reset()

    try {
      tables.value = (await api<{ tables: TableSummary[] }>('GET', `/web/connections/${id}/tables`)).tables
    } catch (error) {
      tablesError.value = messageFor(error)
    }
  }

  function close(): void {
    connectionId.value = null
    tables.value = []
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

  async function describe(name: string): Promise<TableDetail> {
    const known = described.get(name)
    if (known) {
      return known
    }
    const { table } = await api<{ table: TableDetail }>('GET', `/web/connections/${connectionId.value ?? ''}/tables/${encodeURIComponent(name)}`)
    described.set(name, table)

    return table
  }

  return { connectionId, tables, tablesError, tabs, activeTab, open, close, addTab, closeTab, select, setSql, setMode, run, confirmPending, cancelPending, describe }
})
