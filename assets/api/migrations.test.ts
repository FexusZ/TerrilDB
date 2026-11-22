import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError, api } from './client'
import { fetchMigrations, runMigrationAction } from './migrations'

vi.mock('./client', async (importOriginal) => ({ ...(await importOriginal<typeof import('./client')>()), api: vi.fn() }))

const mocked = vi.mocked(api)
const STATEMENT = "ALTER VITESS_MIGRATION 'ffc701c7_e6a9_11f1_baef_9a5268ba1852' CANCEL"

describe('migrations API', () => {
  beforeEach(() => mocked.mockReset())

  it('reads the migrations of the explored keyspace', async () => {
    mocked.mockResolvedValueOnce({ status: { availability: 'available', reason: null, privilege: null }, truncated: false, migrations: [] })

    const report = await fetchMigrations('abc', { database: 'shop', schema: null })

    expect(mocked).toHaveBeenCalledWith('GET', '/web/connections/abc/diagnostics/migrations?database=shop')
    expect(report.migrations).toEqual([])
  })

  it('asks the console to confirm an action before running it', async () => {
    mocked.mockRejectedValueOnce(new ApiError(409, 'confirmation_required', { reason: 'destructive_statement' }))

    const outcome = await runMigrationAction('abc', STATEMENT, false, { database: 'shop', schema: null })

    expect(outcome).toBe('needs_confirmation')
    expect(mocked).toHaveBeenCalledWith('POST', '/web/connections/abc/query', { sql: STATEMENT, mode: 'read_write', confirmed: false, database: 'shop' })
  })

  it('sends the same statement with the confirmation once the user agreed', async () => {
    mocked.mockResolvedValueOnce({ columns: [], rows: [], rowCount: 0 })

    const outcome = await runMigrationAction('abc', STATEMENT, true, { database: null, schema: null })

    expect(outcome).toBe('done')
    expect(mocked).toHaveBeenCalledWith('POST', '/web/connections/abc/query', { sql: STATEMENT, mode: 'read_write', confirmed: true })
  })

  it('lets any other error through', async () => {
    mocked.mockRejectedValueOnce(new ApiError(403, 'query_refused', { reason: 'write_not_permitted' }))

    await expect(runMigrationAction('abc', STATEMENT, true, { database: null, schema: null })).rejects.toBeInstanceOf(ApiError)
  })
})
