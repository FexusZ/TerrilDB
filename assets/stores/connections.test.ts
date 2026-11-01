import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { api } from '../api/client'
import type { ConnectionInput, ConnectionSummary } from '../api/types'
import { useConnectionsStore } from './connections'

vi.mock('../api/client', async (importOriginal) => ({ ...(await importOriginal<typeof import('../api/client')>()), api: vi.fn() }))

const mocked = vi.mocked(api)
const production: ConnectionSummary = { id: 'a', name: 'Production', engine: 'postgresql', createdAt: '2026-11-01T10:00:00+00:00', updatedAt: '2026-11-01T10:00:00+00:00' }
const input: ConnectionInput = { name: 'Production', engine: 'postgresql', host: 'db.exemple.test', port: 5432, database: 'shop', username: 'lecteur', password: 'secret-value' }

describe('connections store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    mocked.mockReset()
  })

  it('loads the list', async () => {
    mocked.mockResolvedValue({ connections: [production] })
    const store = useConnectionsStore()

    await store.load()

    expect(store.items).toEqual([production])
    expect(store.loaded).toBe(true)
    expect(store.byId('a')).toEqual(production)
    expect(store.byId('zzz')).toBeUndefined()
  })

  it('adds a created connection to the list', async () => {
    mocked.mockResolvedValue({ connection: production })
    const store = useConnectionsStore()

    const created = await store.create(input)

    expect(mocked).toHaveBeenCalledWith('POST', '/web/connections', input)
    expect(created).toEqual(production)
    expect(store.items).toEqual([production])
  })

  it('replaces an updated connection', async () => {
    mocked.mockResolvedValueOnce({ connections: [production] })
    const store = useConnectionsStore()
    await store.load()

    mocked.mockResolvedValueOnce({ connection: { ...production, name: 'Staging' } })
    await store.update('a', { ...input, name: 'Staging' })

    expect(mocked).toHaveBeenLastCalledWith('PUT', '/web/connections/a', { ...input, name: 'Staging' })
    expect(store.items[0]?.name).toBe('Staging')
  })

  it('removes a deleted connection', async () => {
    mocked.mockResolvedValueOnce({ connections: [production] })
    const store = useConnectionsStore()
    await store.load()

    mocked.mockResolvedValueOnce(undefined)
    await store.remove('a')

    expect(mocked).toHaveBeenLastCalledWith('DELETE', '/web/connections/a')
    expect(store.items).toEqual([])
  })

  it('reads the saved parameters of a connection, without any password', async () => {
    mocked.mockResolvedValue({ connection: production, parameters: { host: 'db.exemple.test', port: 5432, database: 'shop', username: 'lecteur', hasPassword: true } })

    const detail = await useConnectionsStore().fetch('a')

    expect(mocked).toHaveBeenCalledWith('GET', '/web/connections/a')
    expect(detail.parameters.hasPassword).toBe(true)
  })

  it('tests unsaved parameters and saved connections', async () => {
    mocked.mockResolvedValue({ ok: true, durationMs: 23 })
    const store = useConnectionsStore()

    expect(await store.probe(input)).toBe(23)
    expect(mocked).toHaveBeenLastCalledWith('POST', '/web/connections/test', input)
    expect(await store.testSaved('a')).toBe(23)
    expect(mocked).toHaveBeenLastCalledWith('POST', '/web/connections/a/test')
  })

  it('forgets everything on demand', async () => {
    mocked.mockResolvedValue({ connections: [production] })
    const store = useConnectionsStore()
    await store.load()

    store.reset()

    expect(store.items).toEqual([])
    expect(store.loaded).toBe(false)
  })
})
