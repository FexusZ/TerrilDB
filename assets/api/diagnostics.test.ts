import { beforeEach, describe, expect, it, vi } from 'vitest'
import { api } from './client'
import { fetchAccounts, fetchDiagnosticCapabilities, fetchEncryption, fetchLocks, fetchSessions } from './diagnostics'

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

  it('reads the accounts and the encryption of the explored database', async () => {
    mocked.mockResolvedValueOnce({ status: { availability: 'available', reason: null, privilege: null }, truncated: false, activatesAllRoles: false, accounts: [], findings: [] })
    mocked.mockResolvedValueOnce({ status: { availability: 'available', reason: null, privilege: null }, encrypted: true, protocol: 'TLSv1.3', cipher: 'c' })

    await fetchAccounts('abc', { database: 'shop', schema: null })
    const encryption = await fetchEncryption('abc', { database: null, schema: null })

    expect(mocked).toHaveBeenCalledWith('GET', '/web/connections/abc/diagnostics/accounts?database=shop')
    expect(mocked).toHaveBeenCalledWith('GET', '/web/connections/abc/diagnostics/encryption')
    expect(encryption.protocol).toBe('TLSv1.3')
  })
})
