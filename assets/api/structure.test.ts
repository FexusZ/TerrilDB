import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { StructurePayload } from './types'
import { api } from './client'
import { applyStructure, previewStructure } from './structure'

vi.mock('./client', async (importOriginal) => ({ ...(await importOriginal<typeof import('./client')>()), api: vi.fn() }))

const mocked = vi.mocked(api)
const draft: StructurePayload = { name: 'orders', columns: [], indexes: [], foreignKeys: [] }

describe('structure API', () => {
  beforeEach(() => mocked.mockReset())

  it('previews a draft against the fingerprint it was built on', async () => {
    mocked.mockResolvedValueOnce({ statements: [], transactional: true, destructive: false, fingerprint: 'f', stale: false })

    await previewStructure('abc', 'orders', { draft, fingerprint: 'f' }, { database: null, schema: null })

    expect(mocked).toHaveBeenCalledWith('POST', '/web/connections/abc/tables/orders/structure/preview', { draft, fingerprint: 'f' })
  })

  it('encodes the table name and passes the explored database and schema', async () => {
    mocked.mockResolvedValueOnce({ statements: [], transactional: true, destructive: false, table: null })

    await applyStructure('abc', 'my table/x', { drop: true, fingerprint: 'f', confirmed: true }, { database: 'shop', schema: 'sales' })

    expect(mocked).toHaveBeenCalledWith('POST', '/web/connections/abc/tables/my%20table%2Fx/structure/apply', { drop: true, fingerprint: 'f', confirmed: true, database: 'shop', schema: 'sales' })
  })
})
