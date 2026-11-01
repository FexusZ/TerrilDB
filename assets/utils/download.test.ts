import { afterEach, describe, expect, it, vi } from 'vitest'
import { saveFile } from './download'

describe('saveFile', () => {
  afterEach(() => vi.restoreAllMocks())

  it('offers the file to the browser and cleans up after itself', () => {
    const create = vi.fn(() => 'blob:terril-1')
    const revoke = vi.fn()
    vi.stubGlobal('URL', Object.assign(URL, { createObjectURL: create, revokeObjectURL: revoke }))
    const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => undefined)

    saveFile(new Blob(['x']), 'export.csv')

    expect(create).toHaveBeenCalledOnce()
    expect(click).toHaveBeenCalledOnce()
    expect(revoke).toHaveBeenCalledWith('blob:terril-1')
    expect(document.querySelector('a[download]')).toBeNull()
  })

  it('names the file as given', () => {
    vi.stubGlobal('URL', Object.assign(URL, { createObjectURL: () => 'blob:x', revokeObjectURL: vi.fn() }))
    let name = ''
    vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function (this: HTMLAnchorElement) {
      name = this.download
    })

    saveFile(new Blob(['x']), 'terrildb-export-1.json')

    expect(name).toBe('terrildb-export-1.json')
  })
})
