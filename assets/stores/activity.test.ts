import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError, api } from '../api/client'
import type { AuditEntry } from '../api/types'
import { useActivityStore } from './activity'

vi.mock('../api/client', async (importOriginal) => ({ ...(await importOriginal<typeof import('../api/client')>()), api: vi.fn() }))

const mocked = vi.mocked(api)
const entry = (id: string): AuditEntry => ({ id, occurredAt: '2026-11-01T10:00:00+00:00', action: 'login.success', category: 'login', outcome: 'success', actor: 'web', ip: null, subjectId: null, tokenId: null, details: {} })

describe('activity store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    mocked.mockReset()
  })

  it('loads the first page', async () => {
    mocked.mockResolvedValue({ events: [entry('a'), entry('b')], next: 'b' })
    const store = useActivityStore()

    await store.load()

    expect(mocked).toHaveBeenCalledWith('GET', '/web/activity?limit=50')
    expect(store.items.map((item) => item.id)).toEqual(['a', 'b'])
    expect(store.hasMore).toBe(true)
  })

  it('filters by category', async () => {
    mocked.mockResolvedValue({ events: [], next: null })
    const store = useActivityStore()

    await store.load('query')

    expect(mocked).toHaveBeenCalledWith('GET', '/web/activity?limit=50&category=query')
    expect(store.category).toBe('query')
    expect(store.hasMore).toBe(false)
  })

  it('appends the next page after the cursor', async () => {
    mocked.mockResolvedValueOnce({ events: [entry('a')], next: 'a' })
    const store = useActivityStore()
    await store.load()

    mocked.mockResolvedValueOnce({ events: [entry('b')], next: null })
    await store.more()

    expect(mocked).toHaveBeenLastCalledWith('GET', '/web/activity?limit=50&before=a')
    expect(store.items.map((item) => item.id)).toEqual(['a', 'b'])
    expect(store.hasMore).toBe(false)
  })

  it('keeps the category while paging', async () => {
    mocked.mockResolvedValueOnce({ events: [entry('a')], next: 'a' })
    const store = useActivityStore()
    await store.load('login')

    mocked.mockResolvedValueOnce({ events: [], next: null })
    await store.more()

    expect(mocked).toHaveBeenLastCalledWith('GET', '/web/activity?limit=50&category=login&before=a')
  })

  it('starts over when the filter changes', async () => {
    mocked.mockResolvedValueOnce({ events: [entry('a')], next: 'a' })
    const store = useActivityStore()
    await store.load()
    mocked.mockResolvedValueOnce({ events: [entry('z')], next: null })

    await store.load('token')

    expect(store.items.map((item) => item.id)).toEqual(['z'])
  })

  it('does nothing when asked for more without a next page', async () => {
    mocked.mockResolvedValue({ events: [entry('a')], next: null })
    const store = useActivityStore()
    await store.load()

    await store.more()

    expect(mocked).toHaveBeenCalledTimes(1)
  })

  it('reports why loading failed', async () => {
    mocked.mockRejectedValueOnce(new ApiError(0, 'network'))
    const store = useActivityStore()

    await store.load()

    expect(store.error).toContain('serveur')
    expect(store.items).toEqual([])
  })

  it('forgets everything on demand', async () => {
    mocked.mockResolvedValue({ events: [entry('a')], next: 'a' })
    const store = useActivityStore()
    await store.load()

    store.reset()

    expect(store.items).toEqual([])
    expect(store.hasMore).toBe(false)
  })
})
