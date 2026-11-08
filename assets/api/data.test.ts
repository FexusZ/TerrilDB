import { beforeEach, describe, expect, it, vi } from 'vitest'
import { api } from './client'
import { applyData, previewData } from './data'

vi.mock('./client', async (importOriginal) => ({ ...(await importOriginal<typeof import('./client')>()), api: vi.fn() }))

const mocked = vi.mocked(api)
const payload = { updates: [], inserts: [{ values: { name: 'x' } }], deletes: [] }

describe('data API', () => {
  beforeEach(() => mocked.mockReset())

  it('previews the rows without anything but rows', async () => {
    mocked.mockResolvedValueOnce({ statements: [], ok: true })

    await previewData('abc', 'orders', payload, { database: null, schema: null })

    expect(mocked).toHaveBeenCalledWith('POST', '/web/connections/abc/tables/orders/data/preview', payload)
  })

  it('applies with the confirmation, the table name encoded and the explored scope', async () => {
    mocked.mockResolvedValueOnce({ statements: [] })

    await applyData('abc', 'my table/x', payload, true, { database: 'shop', schema: 'sales' })

    expect(mocked).toHaveBeenCalledWith('POST', '/web/connections/abc/tables/my%20table%2Fx/data/apply', { ...payload, confirmed: true, database: 'shop', schema: 'sales' })
  })
})
