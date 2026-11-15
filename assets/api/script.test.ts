import { beforeEach, describe, expect, it, vi } from 'vitest'
import { api } from './client'
import { executeScript, previewScript } from './script'

vi.mock('./client', async (importOriginal) => ({ ...(await importOriginal<typeof import('./client')>()), api: vi.fn() }))

const mocked = vi.mocked(api)

describe('script API', () => {
  beforeEach(() => mocked.mockReset())

  it('previews the script with the explored scope and nothing else', async () => {
    mocked.mockResolvedValueOnce({ steps: [] })

    await previewScript('abc', 'select 1; select 2', { database: 'shop', schema: null })

    expect(mocked).toHaveBeenCalledWith('POST', '/web/connections/abc/script/preview', { script: 'select 1; select 2', database: 'shop' })
  })

  it('executes with the single confirmation and the dry run flag', async () => {
    mocked.mockResolvedValueOnce({ status: 'committed' })

    await executeScript('abc', 'drop table t', { confirmed: true, dryRun: true }, { database: null, schema: 'sales' })

    expect(mocked).toHaveBeenCalledWith('POST', '/web/connections/abc/script/execute', { script: 'drop table t', confirmed: true, dryRun: true, schema: 'sales' })
  })
})
