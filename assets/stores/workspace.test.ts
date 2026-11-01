import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError, api, apiDownload } from '../api/client'
import type { HistoryEntry, PlanResult, QueryResult, TableDetail, TableSummary } from '../api/types'
import { saveFile } from '../utils/download'
import { useWorkspaceStore } from './workspace'

vi.mock('../api/client', async (importOriginal) => ({ ...(await importOriginal<typeof import('../api/client')>()), api: vi.fn(), apiDownload: vi.fn() }))
vi.mock('../utils/download', () => ({ saveFile: vi.fn() }))

const mocked = vi.mocked(api)
const tables: TableSummary[] = [{ name: 'orders', kind: 'table', estimatedRows: 3800 }]
const result: QueryResult = { columns: ['a'], rows: [[1]], rowCount: 1, truncated: false, affectedRows: null, durationMs: 4 }
const detail: TableDetail = { name: 'orders', kind: 'table', estimatedRows: 3800, viewDefinition: null, columns: [], indexes: [], foreignKeys: [] }

const entry = (id: string, sql: string): HistoryEntry => ({ id, occurredAt: '2026-11-01T10:00:00+00:00', actor: 'web', outcome: 'success', sql, mode: 'read_only', rowCount: 1, durationMs: 3, reason: null })

async function opened() {
  mocked.mockResolvedValueOnce({ tables })
  const store = useWorkspaceStore()
  await store.open('conn-1')

  return store
}

describe('workspace store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    mocked.mockReset()
  })

  it('opens a connection: loads its tables and starts with one empty read-only tab', async () => {
    const store = await opened()

    expect(mocked).toHaveBeenCalledWith('GET', '/web/connections/conn-1/tables')
    expect(store.tables).toEqual(tables)
    expect(store.tabs).toHaveLength(1)
    expect(store.activeTab.title).toBe('Requête 1')
    expect(store.activeTab.mode).toBe('read_only')
  })

  it('reports why the tables could not be loaded', async () => {
    mocked.mockRejectedValueOnce(new ApiError(502, 'target_unreachable'))
    const store = useWorkspaceStore()

    await store.open('conn-1')

    expect(store.tablesError).toContain('joindre')
    expect(store.tables).toEqual([])
  })

  it('does not start over when the same connection is opened again', async () => {
    const store = await opened()
    store.setSql(store.activeTab.id, 'select 1')

    await store.open('conn-1')

    expect(store.activeTab.sql).toBe('select 1')
    expect(mocked).toHaveBeenCalledTimes(1)
  })

  it('starts over when another connection is opened', async () => {
    const store = await opened()
    store.setSql(store.activeTab.id, 'select 1')

    mocked.mockResolvedValueOnce({ tables: [] })
    await store.open('conn-2')

    expect(store.activeTab.sql).toBe('')
  })

  it('runs the active query and keeps its result', async () => {
    const store = await opened()
    store.setSql(store.activeTab.id, 'select 1')
    mocked.mockResolvedValueOnce(result)

    await store.run(store.activeTab.id)

    expect(mocked).toHaveBeenLastCalledWith('POST', '/web/connections/conn-1/query', { sql: 'select 1', mode: 'read_only', confirmed: false })
    expect(store.activeTab.result).toEqual(result)
    expect(store.activeTab.error).toBeNull()
    expect(store.activeTab.running).toBe(false)
  })

  it('does not call the server for an empty statement', async () => {
    const store = await opened()
    store.setSql(store.activeTab.id, '   ')

    await store.run(store.activeTab.id)

    expect(mocked).toHaveBeenCalledTimes(1)
    expect(store.activeTab.error).toBe('Saisissez une instruction.')
  })

  it('shows the engine message when a query fails, and drops the previous result', async () => {
    const store = await opened()
    store.setSql(store.activeTab.id, 'select 1')
    mocked.mockResolvedValueOnce(result)
    await store.run(store.activeTab.id)

    mocked.mockRejectedValueOnce(new ApiError(400, 'query_failed', { message: 'syntax error' }))
    await store.run(store.activeTab.id)

    expect(store.activeTab.error).toBe('syntax error')
    expect(store.activeTab.result).toBeNull()
  })

  it('asks for confirmation instead of showing an error, then re-sends with the confirmation', async () => {
    const store = await opened()
    const id = store.activeTab.id
    store.setSql(id, 'delete from orders')
    store.setMode(id, 'read_write')
    mocked.mockRejectedValueOnce(new ApiError(409, 'confirmation_required', { reason: 'destructive_without_where' }))

    await store.run(id)

    expect(store.activeTab.pendingConfirmation).toBe('destructive_without_where')
    expect(store.activeTab.error).toBeNull()

    mocked.mockResolvedValueOnce({ ...result, columns: [], rows: [], rowCount: 0, affectedRows: 3 })
    await store.confirmPending(id)

    expect(mocked).toHaveBeenLastCalledWith('POST', '/web/connections/conn-1/query', { sql: 'delete from orders', mode: 'read_write', confirmed: true })
    expect(store.activeTab.pendingConfirmation).toBeNull()
    expect(store.activeTab.result?.affectedRows).toBe(3)
  })

  it('can decline a confirmation', async () => {
    const store = await opened()
    const id = store.activeTab.id
    store.setSql(id, 'delete from orders')
    mocked.mockRejectedValueOnce(new ApiError(409, 'confirmation_required', { reason: 'destructive_without_where' }))
    await store.run(id)

    store.cancelPending(id)

    expect(store.activeTab.pendingConfirmation).toBeNull()
    expect(mocked).toHaveBeenCalledTimes(2)
  })

  it('editing the statement withdraws a pending confirmation', async () => {
    const store = await opened()
    const id = store.activeTab.id
    store.setSql(id, 'delete from orders')
    mocked.mockRejectedValueOnce(new ApiError(409, 'confirmation_required', { reason: 'destructive_without_where' }))
    await store.run(id)

    store.setSql(id, 'delete from orders where id = 1')

    expect(store.activeTab.pendingConfirmation).toBeNull()
  })

  it('explains a refusal', async () => {
    const store = await opened()
    store.setSql(store.activeTab.id, 'drop table orders')
    mocked.mockRejectedValueOnce(new ApiError(403, 'query_refused', { reason: 'not_read_only' }))

    await store.run(store.activeTab.id)

    expect(store.activeTab.error).toContain('lecture seule')
  })

  it('manages tabs: adds, numbers, closes and always keeps one', async () => {
    const store = await opened()
    const second = store.addTab('select 2')

    expect(store.tabs.map((tab) => tab.title)).toEqual(['Requête 1', 'Requête 2'])
    expect(store.activeTab.id).toBe(second)
    expect(store.activeTab.sql).toBe('select 2')

    store.closeTab(second)
    expect(store.tabs).toHaveLength(1)
    expect(store.activeTab.title).toBe('Requête 1')

    store.closeTab(store.activeTab.id)
    expect(store.tabs).toHaveLength(1)
    expect(store.activeTab.sql).toBe('')
  })

  it('ignores the answer of a statement that was replaced by a newer run', async () => {
    const store = await opened()
    const id = store.activeTab.id
    store.setSql(id, 'select 1')
    let resolveFirst: (value: QueryResult) => void = () => undefined
    mocked.mockImplementationOnce(() => new Promise((resolve) => { resolveFirst = resolve as (value: QueryResult) => void }))
    const first = store.run(id)

    mocked.mockResolvedValueOnce({ ...result, durationMs: 99 })
    await store.run(id)
    resolveFirst({ ...result, durationMs: 1 })
    await first

    expect(store.activeTab.result?.durationMs).toBe(99)
  })

  it('describes a table once and keeps it', async () => {
    const store = await opened()
    mocked.mockResolvedValueOnce({ table: detail })

    const first = await store.describe('orders')
    const second = await store.describe('orders')

    expect(first).toEqual(detail)
    expect(second).toBe(first)
    expect(mocked).toHaveBeenLastCalledWith('GET', '/web/connections/conn-1/tables/orders')
    expect(mocked).toHaveBeenCalledTimes(2)
  })

  it('encodes odd table names in the address', async () => {
    const store = await opened()
    mocked.mockResolvedValueOnce({ table: { ...detail, name: 'a b/c' } })

    await store.describe('a b/c')

    expect(mocked).toHaveBeenLastCalledWith('GET', '/web/connections/conn-1/tables/a%20b%2Fc')
  })

  describe('history', () => {
    it('loads the history of the open connection', async () => {
      const store = await opened()
      mocked.mockResolvedValueOnce({ entries: [entry('a', 'select 1'), entry('b', 'select 2')], next: 'b' })

      await store.loadHistory()

      expect(mocked).toHaveBeenLastCalledWith('GET', '/web/connections/conn-1/history?limit=50')
      expect(store.history.map((item) => item.sql)).toEqual(['select 1', 'select 2'])
      expect(store.historyLoaded).toBe(true)
      expect(store.historyHasMore).toBe(true)
    })

    it('appends the next page', async () => {
      const store = await opened()
      mocked.mockResolvedValueOnce({ entries: [entry('a', 'select 1')], next: 'a' })
      await store.loadHistory()
      mocked.mockResolvedValueOnce({ entries: [entry('b', 'select 0')], next: null })

      await store.moreHistory()

      expect(mocked).toHaveBeenLastCalledWith('GET', '/web/connections/conn-1/history?limit=50&before=a')
      expect(store.history.map((item) => item.id)).toEqual(['a', 'b'])
      expect(store.historyHasMore).toBe(false)
    })

    it('reports a failure', async () => {
      const store = await opened()
      mocked.mockRejectedValueOnce(new ApiError(0, 'network'))

      await store.loadHistory()

      expect(store.historyError).toContain('serveur')
    })

    it('stays untouched by a run until the history has been opened', async () => {
      const store = await opened()
      store.setSql(store.activeTab.id, 'select 1')
      mocked.mockResolvedValueOnce(result)

      await store.run(store.activeTab.id)

      expect(mocked).toHaveBeenCalledTimes(2)
    })

    it('refreshes after a run once it has been opened', async () => {
      const store = await opened()
      mocked.mockResolvedValueOnce({ entries: [], next: null })
      await store.loadHistory()
      store.setSql(store.activeTab.id, 'select 1')
      mocked.mockResolvedValueOnce(result)
      mocked.mockResolvedValueOnce({ entries: [entry('a', 'select 1')], next: null })

      await store.run(store.activeTab.id)

      expect(mocked).toHaveBeenLastCalledWith('GET', '/web/connections/conn-1/history?limit=50')
      expect(store.history.map((item) => item.sql)).toEqual(['select 1'])
    })

    it('is forgotten when another connection is opened', async () => {
      const store = await opened()
      mocked.mockResolvedValueOnce({ entries: [entry('a', 'select 1')], next: null })
      await store.loadHistory()

      mocked.mockResolvedValueOnce({ tables: [] })
      await store.open('conn-2')

      expect(store.history).toEqual([])
      expect(store.historyLoaded).toBe(false)
    })
  })

  describe('export', () => {
    it('downloads the statement of the tab as a file', async () => {
      const store = await opened()
      store.setSql(store.activeTab.id, 'select 1')
      const blob = new Blob(['id\r\n1\r\n'])
      vi.mocked(apiDownload).mockResolvedValueOnce({ blob, filename: 'terrildb-export-1.csv', truncated: false })

      await store.exportTab(store.activeTab.id, 'csv')

      expect(apiDownload).toHaveBeenCalledWith('/web/connections/conn-1/export', { sql: 'select 1', format: 'csv' })
      expect(saveFile).toHaveBeenCalledWith(blob, 'terrildb-export-1.csv')
      expect(store.activeTab.exportNotice).toBeNull()
      expect(store.activeTab.exporting).toBe(false)
    })

    it('says when the file was cut at the limit', async () => {
      const store = await opened()
      store.setSql(store.activeTab.id, 'select 1')
      vi.mocked(apiDownload).mockResolvedValueOnce({ blob: new Blob(['x']), filename: 'f.json', truncated: true })

      await store.exportTab(store.activeTab.id, 'json')

      expect(store.activeTab.exportNotice).toContain('limité')
    })

    it('shows why an export failed', async () => {
      const store = await opened()
      store.setSql(store.activeTab.id, 'drop table t')
      vi.mocked(apiDownload).mockRejectedValueOnce(new ApiError(403, 'query_refused', { reason: 'not_read_only' }))

      await store.exportTab(store.activeTab.id, 'csv')

      expect(store.activeTab.error).toContain('lecture seule')
      expect(saveFile).not.toHaveBeenCalledWith(expect.anything(), 'f')
    })

    it('does not export an empty statement', async () => {
      const store = await opened()
      vi.mocked(apiDownload).mockClear()

      await store.exportTab(store.activeTab.id, 'csv')

      expect(apiDownload).not.toHaveBeenCalled()
      expect(store.activeTab.error).toBe('Saisissez une instruction.')
    })
  })

  describe('plan', () => {
    const plan: PlanResult = { plan: { totalCost: 5, root: { kind: 'full_scan', label: 'Seq Scan', relation: 'orders', index: null, rows: 3800, cost: 5, condition: null, fullScan: true, children: [] } }, findings: [{ rule: 'full_scan', relation: 'orders', rows: 3800, condition: null }] }

    it('asks for the plan of the statement of the tab', async () => {
      const store = await opened()
      store.setSql(store.activeTab.id, 'select * from orders')
      mocked.mockResolvedValueOnce(plan)

      await store.planTab(store.activeTab.id)

      expect(mocked).toHaveBeenLastCalledWith('POST', '/web/connections/conn-1/plan', { sql: 'select * from orders' })
      expect(store.activeTab.plan).toEqual(plan)
      expect(store.activeTab.view).toBe('plan')
      expect(store.activeTab.planning).toBe(false)
    })

    it('shows the result again after a run', async () => {
      const store = await opened()
      store.setSql(store.activeTab.id, 'select 1')
      mocked.mockResolvedValueOnce(plan)
      await store.planTab(store.activeTab.id)
      mocked.mockResolvedValueOnce(result)

      await store.run(store.activeTab.id)

      expect(store.activeTab.view).toBe('result')
    })

    it('does not ask for the plan of an empty statement', async () => {
      const store = await opened()

      await store.planTab(store.activeTab.id)

      expect(mocked).toHaveBeenCalledTimes(1)
      expect(store.activeTab.error).toBe('Saisissez une instruction.')
    })

    it('explains a refusal', async () => {
      const store = await opened()
      store.setSql(store.activeTab.id, 'delete from orders')
      mocked.mockRejectedValueOnce(new ApiError(403, 'query_refused', { reason: 'not_read_only' }))

      await store.planTab(store.activeTab.id)

      expect(store.activeTab.error).toContain('lecture seule')
      expect(store.activeTab.plan).toBeNull()
    })

    it('forgets the plan when the statement is edited', async () => {
      const store = await opened()
      store.setSql(store.activeTab.id, 'select * from orders')
      mocked.mockResolvedValueOnce(plan)
      await store.planTab(store.activeTab.id)

      store.setSql(store.activeTab.id, 'select * from orders where id = 1')

      expect(store.activeTab.plan).toBeNull()
      expect(store.activeTab.view).toBe('result')
    })
  })
})
