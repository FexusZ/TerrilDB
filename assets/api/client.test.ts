import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError, api, apiDownload, onSessionLost } from './client'

function respond(status: number, body?: unknown): Response {
  return new Response(body === undefined ? null : JSON.stringify(body), {
    status,
    headers: body === undefined ? {} : { 'Content-Type': 'application/json' },
  })
}

describe('api client', () => {
  const fetchMock = vi.fn()

  beforeEach(() => {
    fetchMock.mockReset()
    vi.stubGlobal('fetch', fetchMock)
    onSessionLost(null)
  })
  afterEach(() => vi.unstubAllGlobals())

  it('sends the CSRF header and JSON on every request', async () => {
    fetchMock.mockResolvedValue(respond(200, { ok: true }))

    await api('POST', '/web/login', { login: 'alice' })

    const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit]
    expect(url).toBe('/web/login')
    expect(init.method).toBe('POST')
    expect(init.credentials).toBe('same-origin')
    const headers = new Headers(init.headers)
    expect(headers.get('X-TerrilDB-Request')).toBe('1')
    expect(headers.get('Accept')).toBe('application/json')
    expect(headers.get('Content-Type')).toBe('application/json')
    expect(init.body).toBe(JSON.stringify({ login: 'alice' }))
  })

  it('sends no body and no content type for a plain read', async () => {
    fetchMock.mockResolvedValue(respond(200, {}))

    await api('GET', '/web/me')

    const [, init] = fetchMock.mock.calls[0] as [string, RequestInit]
    expect(init.body).toBeUndefined()
    expect(new Headers(init.headers).has('Content-Type')).toBe(false)
  })

  it('returns the decoded body, or nothing for a 204', async () => {
    fetchMock.mockResolvedValueOnce(respond(200, { user: { username: 'alice' } }))
    expect(await api('GET', '/web/me')).toEqual({ user: { username: 'alice' } })

    fetchMock.mockResolvedValueOnce(respond(204))
    expect(await api('POST', '/web/logout')).toBeUndefined()
  })

  it('turns an error body into an ApiError with its code and details', async () => {
    fetchMock.mockResolvedValue(respond(403, { error: 'query_refused', reason: 'not_read_only' }))

    const error = await api('POST', '/web/connections/x/query', { sql: 'drop table t' }).catch((e: unknown) => e)

    expect(error).toBeInstanceOf(ApiError)
    expect(error).toMatchObject({ status: 403, code: 'query_refused', details: { reason: 'not_read_only' } })
  })

  it('copes with an error that is not JSON', async () => {
    fetchMock.mockResolvedValue(new Response('<html>Bad gateway</html>', { status: 502 }))

    const error = await api('GET', '/web/me').catch((e: unknown) => e)

    expect(error).toMatchObject({ status: 502, code: 'http_error' })
  })

  it('reports an unreachable server as a network error', async () => {
    fetchMock.mockRejectedValue(new TypeError('Failed to fetch'))

    const error = await api('GET', '/web/me').catch((e: unknown) => e)

    expect(error).toMatchObject({ status: 0, code: 'network' })
  })

  it('tells the app when the session is lost or locked, but not for a wrong password', async () => {
    const lost = vi.fn()
    onSessionLost(lost)

    fetchMock.mockResolvedValueOnce(respond(401, { error: 'invalid_credentials' }))
    await api('POST', '/web/login', {}).catch(() => undefined)
    expect(lost).not.toHaveBeenCalled()

    fetchMock.mockResolvedValueOnce(respond(401, { error: 'unauthenticated' }))
    await api('GET', '/web/connections').catch(() => undefined)
    fetchMock.mockResolvedValueOnce(respond(401, { error: 'locked' }))
    await api('GET', '/web/connections').catch(() => undefined)
    expect(lost).toHaveBeenCalledTimes(2)
  })

  describe('downloads', () => {
    it('returns the file with its name and whether it was cut', async () => {
      fetchMock.mockResolvedValue(new Response('id\r\n1\r\n', { status: 200, headers: { 'Content-Type': 'text/csv', 'Content-Disposition': 'attachment; filename=terrildb-export-20261101-120000.csv', 'X-TerrilDB-Truncated': '1' } }))

      const file = await apiDownload('/web/connections/a/export', { sql: 'select 1', format: 'csv' })

      const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit]
      expect(url).toBe('/web/connections/a/export')
      expect(init.method).toBe('POST')
      expect(new Headers(init.headers).get('X-TerrilDB-Request')).toBe('1')
      expect(file.filename).toBe('terrildb-export-20261101-120000.csv')
      expect(file.truncated).toBe(true)
      expect(await file.blob.text()).toBe('id\r\n1\r\n')
    })

    it('reads a quoted file name', async () => {
      fetchMock.mockResolvedValue(new Response('x', { status: 200, headers: { 'Content-Disposition': 'attachment; filename="a b.json"' } }))

      expect((await apiDownload('/x', {})).filename).toBe('a b.json')
    })

    it('falls back to a generic name and no truncation', async () => {
      fetchMock.mockResolvedValue(new Response('x', { status: 200 }))

      const file = await apiDownload('/x', {})

      expect(file.filename).toBe('export')
      expect(file.truncated).toBe(false)
    })

    it('turns a refusal into an ApiError', async () => {
      fetchMock.mockResolvedValue(respond(403, { error: 'query_refused', reason: 'not_read_only' }))

      const error = await apiDownload('/x', {}).catch((e: unknown) => e)

      expect(error).toMatchObject({ status: 403, code: 'query_refused', details: { reason: 'not_read_only' } })
    })

    it('reports an unreachable server', async () => {
      fetchMock.mockRejectedValue(new TypeError('Failed to fetch'))

      expect(await apiDownload('/x', {}).catch((e: unknown) => e)).toMatchObject({ code: 'network' })
    })
  })
})
