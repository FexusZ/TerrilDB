import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError, api } from '../api/client'
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

  describe('order and color', () => {
    const at = (id: string, name: string): ConnectionSummary => ({ ...production, id, name })
    const names = (store: ReturnType<typeof useConnectionsStore>) => store.items.map((connection) => connection.name)

    it('puts a created connection at the end of the list, not where its name would sort', async () => {
      const store = useConnectionsStore()
      store.items = [at('a', 'Production')]
      mocked.mockResolvedValue({ connection: at('b', 'Analytics') })

      await store.create({ ...input, name: 'Analytics' })

      expect(names(store)).toEqual(['Production', 'Analytics'])
    })

    it('keeps the place of an updated connection', async () => {
      const store = useConnectionsStore()
      store.items = [at('a', 'Zeta'), at('b', 'Alpha')]
      mocked.mockResolvedValue({ connection: { ...at('a', 'Zeta'), color: 'red' } })

      await store.update('a', { ...input, name: 'Zeta', color: 'red' })

      expect(names(store)).toEqual(['Zeta', 'Alpha'])
      expect(store.byId('a')?.color).toBe('red')
    })

    it('reorders at once on screen, then tells the server the full order', async () => {
      const store = useConnectionsStore()
      store.items = [at('a', 'A'), at('b', 'B'), at('c', 'C')]
      mocked.mockResolvedValue(undefined)

      await store.reorder(['c', 'a', 'b'])

      expect(mocked).toHaveBeenCalledWith('PUT', '/web/connections/order', { ids: ['c', 'a', 'b'] })
      expect(names(store)).toEqual(['C', 'A', 'B'])
    })

    it('shows the new order before the server has answered', async () => {
      const store = useConnectionsStore()
      store.items = [at('a', 'A'), at('b', 'B')]
      let answer: (value: unknown) => void = () => undefined
      mocked.mockReturnValueOnce(new Promise((resolve) => { answer = resolve }))

      const pending = store.reorder(['b', 'a'])

      expect(names(store)).toEqual(['B', 'A'])
      answer(undefined)
      await pending
    })

    it('goes back to what the server says when it refuses the order', async () => {
      const store = useConnectionsStore()
      store.items = [at('a', 'A'), at('b', 'B')]
      mocked.mockRejectedValueOnce(new ApiError(422, 'invalid_input'))
      mocked.mockResolvedValueOnce({ connections: [at('a', 'A'), at('b', 'B')] })

      await store.reorder(['b', 'a'])

      expect(mocked).toHaveBeenLastCalledWith('GET', '/web/connections')
      expect(names(store)).toEqual(['A', 'B'])
    })

    it('moves one connection by a step, and does nothing past either end', async () => {
      const store = useConnectionsStore()
      store.items = [at('a', 'A'), at('b', 'B'), at('c', 'C')]
      mocked.mockResolvedValue(undefined)

      await store.move('b', -1)
      expect(names(store)).toEqual(['B', 'A', 'C'])
      mocked.mockClear()
      await store.move('b', -1)
      await store.move('c', 1)

      expect(mocked).not.toHaveBeenCalled()
      expect(names(store)).toEqual(['B', 'A', 'C'])
    })

    it('moves a connection onto the place of another', async () => {
      const store = useConnectionsStore()
      store.items = [at('a', 'A'), at('b', 'B'), at('c', 'C')]
      mocked.mockResolvedValue(undefined)

      await store.moveOnto('a', 'c')
      expect(names(store)).toEqual(['B', 'C', 'A'])
      await store.moveOnto('a', 'b')
      expect(names(store)).toEqual(['A', 'B', 'C'])
    })
  })
})
