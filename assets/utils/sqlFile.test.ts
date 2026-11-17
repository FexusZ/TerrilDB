import { describe, expect, it } from 'vitest'
import { MAX_SCRIPT_BYTES, readSqlFile } from './sqlFile'

const file = (parts: BlobPart[], name = 'dump.sql'): File => new File(parts, name)

describe('readSqlFile', () => {
  it('reads a UTF-8 script and drops the byte order mark', async () => {
    const result = await readSqlFile(file([new Uint8Array([0xef, 0xbb, 0xbf]), "INSERT INTO t VALUES ('é');"]))

    expect(result).toEqual({ ok: true, text: "INSERT INTO t VALUES ('é');" })
  })

  it('refuses a file larger than what the server accepts, without reading it', async () => {
    const big = file(['x'])
    Object.defineProperty(big, 'size', { value: MAX_SCRIPT_BYTES + 1 })

    expect(await readSqlFile(big)).toEqual({ ok: false, reason: 'too_large' })
  })

  it('accepts a file of exactly the limit', async () => {
    const result = await readSqlFile(file(['a'.repeat(MAX_SCRIPT_BYTES)]))

    expect(result.ok).toBe(true)
  })

  it('refuses an empty file or one with only blanks', async () => {
    expect(await readSqlFile(file([]))).toEqual({ ok: false, reason: 'empty' })
    expect(await readSqlFile(file(['  \n\t '] ))).toEqual({ ok: false, reason: 'empty' })
  })

  it('refuses a binary file (NUL byte)', async () => {
    expect(await readSqlFile(file([new Uint8Array([0x53, 0x45, 0x00, 0x4c])]))).toEqual({ ok: false, reason: 'binary' })
  })

  it('refuses text that is not valid UTF-8', async () => {
    expect(await readSqlFile(file([new Uint8Array([0x53, 0xe9, 0x4c])]))).toEqual({ ok: false, reason: 'not_utf8' })
  })

  it('reports an unreadable file', async () => {
    const broken = file(['x'])
    Object.defineProperty(broken, 'arrayBuffer', { value: () => Promise.reject(new Error('denied')) })

    expect(await readSqlFile(broken)).toEqual({ ok: false, reason: 'unreadable' })
  })
})
