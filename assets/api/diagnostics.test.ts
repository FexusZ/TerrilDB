import { beforeEach, describe, expect, it, vi } from 'vitest'
import { api } from './client'
import { fetchDiagnosticCapabilities, fetchLocks, fetchSessions, fetchTopQueries } from './diagnostics'

vi.mock('./client', async (importOriginal) => ({ ...(await importOriginal<typeof import('./client')>()), api: vi.fn() }))

const mocked = vi.mocked(api)

describe('diagnostics API', () => {
  beforeEach(() => mocked.mockReset())

  it('reads what each source can serve', async () => {
    mocked.mockResolvedValueOnce({ capabilities: { sessions: { availability: 'available', reason: null, privilege: null } } })

    const result = await fetchDiagnosticCapabilities('abc', { database: null, schema: null })

    expect(mocked).toHaveBeenCalledWith('GET', '/web/connections/abc/diagnostics')
    expect(result.sessions?.availability).toBe('available')
  })

  it('reads the sessions of the explored database', async () => {
    mocked.mockResolvedValueOnce({ status: { availability: 'permission_denied', reason: 'insufficient_privilege', privilege: 'PROCESS' }, truncated: false, sessions: [] })

    const report = await fetchSessions('abc', { database: 'shop', schema: null })

    expect(mocked).toHaveBeenCalledWith('GET', '/web/connections/abc/diagnostics/sessions?database=shop')
    expect(report.status.privilege).toBe('PROCESS')
  })

  it('encodes the database and schema', async () => {
    mocked.mockResolvedValueOnce({ status: { availability: 'available', reason: null, privilege: null }, truncated: false, sessions: [] })

    await fetchSessions('abc', { database: 'my db', schema: 'a&b' })

    expect(mocked).toHaveBeenCalledWith('GET', '/web/connections/abc/diagnostics/sessions?database=my%20db&schema=a%26b')
  })

  it('reads the locks of the explored database', async () => {
    mocked.mockResolvedValueOnce({ status: { availability: 'available', reason: null, privilege: null }, truncated: false, transactions: [], blocking: [] })

    const report = await fetchLocks('abc', { database: 'shop', schema: 'public' })

    expect(mocked).toHaveBeenCalledWith('GET', '/web/connections/abc/diagnostics/locks?database=shop&schema=public')
    expect(report.blocking).toEqual([])
  })

  it('reads the costliest queries with the order, the limit and the explored database', async () => {
    mocked.mockResolvedValueOnce({ status: { availability: 'available', reason: null, privilege: null }, order: 'calls', digests: [] })

    const report = await fetchTopQueries('abc', { database: 'shop', schema: null }, 'calls', 50)

    expect(mocked).toHaveBeenCalledWith('GET', '/web/connections/abc/diagnostics/queries?order=calls&limit=50&database=shop')
    expect(report.order).toBe('calls')
  })
})
