import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError, api } from '../api/client'
import type { ConnectionSummary, TableSummary } from '../api/types'
import { useConnectionsStore } from './connections'
import { useTreeStore } from './tree'

vi.mock('../api/client', async (importOriginal) => ({ ...(await importOriginal<typeof import('../api/client')>()), api: vi.fn() }))

const mocked = vi.mocked(api)
const connection = (id: string, engine: ConnectionSummary['engine'] = 'postgresql'): ConnectionSummary => ({ id, name: `Base ${id}`, engine, createdAt: '', updatedAt: '' })
const orders: TableSummary = { name: 'orders', kind: 'table', estimatedRows: 10 }
const items: TableSummary = { name: 'items', kind: 'table', estimatedRows: 5 }

function setup(...connections: ConnectionSummary[]) {
  setActivePinia(createPinia())
  useConnectionsStore().items = connections

  return useTreeStore()
}

describe('tree store', () => {
  beforeEach(() => mocked.mockReset())

  it('starts with every connection closed and nothing loaded', () => {
    const tree = setup(connection('c1'))

    expect(tree.statusOf('c1')).toBe('closed')
    expect(tree.isExpanded('c:c1')).toBe(false)
    expect(mocked).not.toHaveBeenCalled()
  })

  it('opens a connection: lists its databases and marks it open', async () => {
    const tree = setup(connection('c1'))
    mocked.mockResolvedValueOnce({ databases: [{ name: 'shop', system: false }, { name: 'reporting', system: false }] })

    await tree.toggleConnection('c1')

    expect(mocked).toHaveBeenCalledWith('GET', '/web/connections/c1/databases')
    expect(tree.statusOf('c1')).toBe('open')
    expect(tree.isExpanded('c:c1')).toBe(true)
    expect(tree.databasesOf('c1')?.map((database) => database.name)).toEqual(['shop', 'reporting'])
    expect(tree.isFlat('c1')).toBe(false)
  })

  it('shows the tables straight under a connection that has a single database', async () => {
    const tree = setup(connection('c1'))
    mocked.mockResolvedValueOnce({ databases: [{ name: 'shop', system: false }] })
    mocked.mockResolvedValueOnce({ tables: [orders], nextCursor: null, total: 1 })

    await tree.toggleConnection('c1')

    expect(tree.isFlat('c1')).toBe(true)
    expect(mocked).toHaveBeenLastCalledWith('GET', '/web/connections/c1/tables?limit=100')
    expect(tree.tablesOf('c1', '')?.items).toEqual([orders])
  })

  it('collapses on the second click and keeps what it loaded', async () => {
    const tree = setup(connection('c1'))
    mocked.mockResolvedValueOnce({ databases: [{ name: 'shop', system: false }, { name: 'other', system: false }] })
    await tree.toggleConnection('c1')

    await tree.toggleConnection('c1')
    expect(tree.isExpanded('c:c1')).toBe(false)
    expect(tree.statusOf('c1')).toBe('closed')

    await tree.toggleConnection('c1')
    expect(tree.isExpanded('c:c1')).toBe(true)
    expect(tree.statusOf('c1')).toBe('open')
    expect(mocked).toHaveBeenCalledTimes(1)
  })

  it('opens a database: loads its first hundred tables with the database named', async () => {
    const tree = setup(connection('c1'))
    mocked.mockResolvedValueOnce({ databases: [{ name: 'shop', system: false }, { name: 'rep orting', system: false }] })
    await tree.toggleConnection('c1')
    mocked.mockResolvedValueOnce({ tables: [orders], nextCursor: 'orders', total: 240 })

    await tree.toggleDatabase('c1', 'rep orting')

    expect(mocked).toHaveBeenLastCalledWith('GET', '/web/connections/c1/tables?limit=100&database=rep+orting')
    expect(tree.isExpanded('d:c1:rep orting')).toBe(true)
    const state = tree.tablesOf('c1', 'rep orting')
    expect(state?.items).toEqual([orders])
    expect(state?.total).toBe(240)
    expect(state?.next).toBe('orders')
  })

  it('loads the next hundred from the cursor and appends them', async () => {
    const tree = setup(connection('c1'))
    mocked.mockResolvedValueOnce({ databases: [{ name: 'a', system: false }, { name: 'b', system: false }] })
    await tree.toggleConnection('c1')
    mocked.mockResolvedValueOnce({ tables: [orders], nextCursor: 'orders', total: 2 })
    await tree.toggleDatabase('c1', 'a')
    mocked.mockResolvedValueOnce({ tables: [items], nextCursor: null, total: null })

    await tree.loadMoreTables('c1', 'a')

    expect(mocked).toHaveBeenLastCalledWith('GET', '/web/connections/c1/tables?limit=100&database=a&cursor=orders')
    const state = tree.tablesOf('c1', 'a')
    expect(state?.items.map((table) => table.name)).toEqual(['orders', 'items'])
    expect(state?.next).toBeNull()
    expect(state?.total).toBe(2)
  })

  it('does not load further while a page is loading or when there is none', async () => {
    const tree = setup(connection('c1'))
    mocked.mockResolvedValueOnce({ databases: [{ name: 'a', system: false }, { name: 'b', system: false }] })
    await tree.toggleConnection('c1')
    mocked.mockResolvedValueOnce({ tables: [orders], nextCursor: null, total: 1 })
    await tree.toggleDatabase('c1', 'a')

    await tree.loadMoreTables('c1', 'a')

    expect(mocked).toHaveBeenCalledTimes(2)
  })

  it('marks a connection unreachable with a generic message, and retries on the next click', async () => {
    const tree = setup(connection('c1'))
    mocked.mockRejectedValueOnce(new ApiError(502, 'target_unreachable'))

    await tree.toggleConnection('c1')

    expect(tree.statusOf('c1')).toBe('unreachable')
    expect(tree.errorOf('c1')).toContain('joindre')
    expect(tree.isExpanded('c:c1')).toBe(false)

    mocked.mockResolvedValueOnce({ databases: [{ name: 'a', system: false }, { name: 'b', system: false }] })
    await tree.toggleConnection('c1')
    expect(tree.statusOf('c1')).toBe('open')
    expect(tree.errorOf('c1')).toBeNull()
  })

  it('reports a database whose tables could not be read, without closing the connection', async () => {
    const tree = setup(connection('c1'))
    mocked.mockResolvedValueOnce({ databases: [{ name: 'a', system: false }, { name: 'b', system: false }] })
    await tree.toggleConnection('c1')
    mocked.mockRejectedValueOnce(new ApiError(502, 'target_unreachable'))

    await tree.toggleDatabase('c1', 'b')

    expect(tree.statusOf('c1')).toBe('open')
    expect(tree.tablesOf('c1', 'b')?.error).toContain('joindre')
    expect(tree.tablesOf('c1', 'b')?.items).toEqual([])
  })

  it('browses the keys of a Redis connection instead of databases and tables', async () => {
    const tree = setup(connection('r1', 'redis'))
    mocked.mockResolvedValueOnce({ keys: [{ name: 'user:1', type: 'string', ttl: null }], nextCursor: '17', total: 120 })

    await tree.toggleConnection('r1')

    expect(mocked).toHaveBeenCalledTimes(1)
    expect(mocked).toHaveBeenCalledWith('GET', '/web/connections/r1/keys?pattern=*&count=100')
    expect(tree.keysOf('r1')?.items.map((key) => key.name)).toEqual(['user:1'])
    expect(tree.keysOf('r1')?.next).toBe('17')
    expect(tree.statusOf('r1')).toBe('open')

    mocked.mockResolvedValueOnce({ keys: [{ name: 'user:2', type: 'string', ttl: null }], nextCursor: null, total: 120 })
    await tree.loadMoreKeys('r1')
    expect(mocked).toHaveBeenLastCalledWith('GET', '/web/connections/r1/keys?pattern=*&count=100&cursor=17')
    expect(tree.keysOf('r1')?.items).toHaveLength(2)
  })

  it('forgets everything about a connection that was deleted', async () => {
    const tree = setup(connection('c1'))
    mocked.mockResolvedValueOnce({ databases: [{ name: 'a', system: false }, { name: 'b', system: false }] })
    await tree.toggleConnection('c1')

    tree.forget('c1')

    expect(tree.statusOf('c1')).toBe('closed')
    expect(tree.isExpanded('c:c1')).toBe(false)
    expect(tree.databasesOf('c1')).toBeNull()
  })

  it('starts again from nothing on reset (logout)', async () => {
    const tree = setup(connection('c1'))
    mocked.mockResolvedValueOnce({ databases: [{ name: 'a', system: false }, { name: 'b', system: false }] })
    await tree.toggleConnection('c1')

    tree.reset()

    expect(tree.statusOf('c1')).toBe('closed')
    expect(tree.isExpanded('c:c1')).toBe(false)
  })

  it('ignores an answer that arrives after the connection was closed and reopened elsewhere', async () => {
    const tree = setup(connection('c1'))
    let release: (value: unknown) => void = () => undefined
    mocked.mockReturnValueOnce(new Promise((resolve) => { release = resolve }))
    const opening = tree.toggleConnection('c1')
    tree.forget('c1')

    release({ databases: [{ name: 'a', system: false }, { name: 'b', system: false }] })
    await opening

    expect(tree.databasesOf('c1')).toBeNull()
    expect(tree.statusOf('c1')).toBe('closed')
  })
})
