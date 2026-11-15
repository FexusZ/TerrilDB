import { beforeEach, describe, expect, it, vi } from 'vitest'
import { api } from './client'
import { fetchDiagnosticCapabilities, fetchLocks, fetchSessions, fetchTopQueries, fetchVariables } from './diagnostics'

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
    mocked.mockResolvedValueOnce({ status: { availability: 'available', reason: null, privilege: null }, order: 'calls', windowHours: null, digests: [] })

    const report = await fetchTopQueries('abc', { database: 'shop', schema: null }, 'calls', 50)

    expect(mocked).toHaveBeenCalledWith('GET', '/web/connections/abc/diagnostics/queries?order=calls&limit=50&database=shop')
    expect(report.order).toBe('calls')
  })

  it('reads the variables with the filters and the explored database', async () => {
    mocked.mockResolvedValueOnce({ status: { availability: 'available', reason: null, privilege: null }, truncated: false, variables: [] })

    await fetchVariables('abc', { database: 'shop', schema: null }, { search: 'max_conn', changedOnly: true, limit: 50 })

    expect(mocked).toHaveBeenCalledWith('GET', '/web/connections/abc/diagnostics/variables?q=max_conn&changed=1&limit=50&database=shop')
  })

  it('sends no filter when none is asked', async () => {
    mocked.mockResolvedValueOnce({ status: { availability: 'available', reason: null, privilege: null }, truncated: false, variables: [] })

    await fetchVariables('abc', { database: null, schema: null }, { search: '', changedOnly: false, limit: 100 })

    expect(mocked).toHaveBeenCalledWith('GET', '/web/connections/abc/diagnostics/variables?limit=100')
  })

  it('encodes the search', async () => {
    mocked.mockResolvedValueOnce({ status: { availability: 'available', reason: null, privilege: null }, truncated: false, variables: [] })

    await fetchVariables('abc', { database: null, schema: null }, { search: 'a&b%', changedOnly: false, limit: 10 })

    expect(mocked).toHaveBeenCalledWith('GET', '/web/connections/abc/diagnostics/variables?q=a%26b%25&limit=10')
  })
})
