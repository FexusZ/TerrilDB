import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { api } from '../api/client'
import type { ApiTokenInfo } from '../api/types'
import { useTokensStore } from './tokens'

vi.mock('../api/client', async (importOriginal) => ({ ...(await importOriginal<typeof import('../api/client')>()), api: vi.fn() }))

const mocked = vi.mocked(api)
const info = (id: string, name: string): ApiTokenInfo => ({ id, name, connectionIds: ['c1'], allConnections: false, writeData: false, writeSchema: false, createConnections: false, diagnostic: false, createdAt: '2026-11-01T10:00:00+00:00', expiresAt: '2026-12-01T10:00:00+00:00', lastUsedAt: null })

describe('tokens store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    mocked.mockReset()
  })

  it('loads the tokens', async () => {
    mocked.mockResolvedValue({ tokens: [info('a', 'Assistant')] })
    const store = useTokensStore()

    await store.load()

    expect(mocked).toHaveBeenCalledWith('GET', '/web/tokens')
    expect(store.items).toEqual([info('a', 'Assistant')])
    expect(store.loaded).toBe(true)
  })

  it('creates a token, keeps it in the list and hands back its text once', async () => {
    mocked.mockResolvedValue({ token: 'tdb_secret', tokenInfo: info('b', 'CI') })
    const store = useTokensStore()

    const text = await store.create({ name: 'CI', connectionIds: ['c1'], lifetimeDays: 30 })

    expect(mocked).toHaveBeenCalledWith('POST', '/web/tokens', { name: 'CI', connectionIds: ['c1'], lifetimeDays: 30 })
    expect(text).toBe('tdb_secret')
    expect(store.items).toEqual([info('b', 'CI')])
    expect(JSON.stringify(store.items)).not.toContain('tdb_secret')
  })

  it('puts the newest token first', async () => {
    mocked.mockResolvedValueOnce({ tokens: [info('a', 'old')] })
    const store = useTokensStore()
    await store.load()

    mocked.mockResolvedValueOnce({ token: 'tdb_x', tokenInfo: info('b', 'new') })
    await store.create({ name: 'new', connectionIds: ['c1'], lifetimeDays: 7 })

    expect(store.items.map((token) => token.name)).toEqual(['new', 'old'])
  })

  it('refreshes a token in place, without touching its text or its position', async () => {
    mocked.mockResolvedValueOnce({ tokens: [info('a', 'one'), info('b', 'two')] })
    const store = useTokensStore()
    await store.load()
    mocked.mockResolvedValueOnce({ tokenInfo: { ...info('b', 'two'), allConnections: true, connectionIds: ['c1', 'c2'] } })

    await store.refresh('b')

    expect(mocked).toHaveBeenLastCalledWith('POST', '/web/tokens/b/refresh')
    expect(store.items.map((token) => token.id)).toEqual(['a', 'b'])
    expect(store.items[1]?.connectionIds).toEqual(['c1', 'c2'])
  })

  it('revokes a token', async () => {
    mocked.mockResolvedValueOnce({ tokens: [info('a', 'one'), info('b', 'two')] })
    const store = useTokensStore()
    await store.load()
    mocked.mockResolvedValueOnce(undefined)

    await store.revoke('a')

    expect(mocked).toHaveBeenLastCalledWith('DELETE', '/web/tokens/a')
    expect(store.items.map((token) => token.id)).toEqual(['b'])
  })

  it('forgets everything on demand', async () => {
    mocked.mockResolvedValue({ tokens: [info('a', 'one')] })
    const store = useTokensStore()
    await store.load()

    store.reset()

    expect(store.items).toEqual([])
    expect(store.loaded).toBe(false)
  })
})
