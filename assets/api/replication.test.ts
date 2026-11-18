import { beforeEach, describe, expect, it, vi } from 'vitest'
import { api } from './client'
import { fetchReplication } from './replication'

vi.mock('./client', async (importOriginal) => ({ ...(await importOriginal<typeof import('./client')>()), api: vi.fn() }))

const mocked = vi.mocked(api)

describe('replication API', () => {
  beforeEach(() => mocked.mockReset())

  it('reads the replication of the explored database', async () => {
    mocked.mockResolvedValueOnce({ role: 'none', channels: [], alerts: [] })

    const report = await fetchReplication('abc', { database: 'shop', schema: null })

    expect(mocked).toHaveBeenCalledWith('GET', '/web/connections/abc/diagnostics/replication?database=shop')
    expect(report.role).toBe('none')
  })
})
