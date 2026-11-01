export type Method = 'GET' | 'POST' | 'PUT' | 'DELETE'

/**
 * Erreur rendue par l'API : `code` est un cas stable (`invalid_credentials`, `query_refused`…), `details` porte
 * le reste du corps (motif, délai avant nouvel essai, message du moteur…).
 */
export class ApiError extends Error {
  constructor(
    readonly status: number,
    readonly code: string,
    readonly details: Record<string, unknown> = {},
  ) {
    super(code)
    this.name = 'ApiError'
  }
}

let sessionLost: (() => void) | null = null

/** Appelé quand une réponse dit que la session n'existe plus ou n'est plus déverrouillée. */
export function onSessionLost(handler: (() => void) | null): void {
  sessionLost = handler
}

const SESSION_LOST_CODES = new Set(['unauthenticated', 'locked'])

export async function api<T = unknown>(method: Method, path: string, body?: unknown): Promise<T> {
  const headers = new Headers({ Accept: 'application/json', 'X-TerrilDB-Request': '1' })
  const init: RequestInit = { method, headers, credentials: 'same-origin' }
  if (body !== undefined) {
    headers.set('Content-Type', 'application/json')
    init.body = JSON.stringify(body)
  }

  let response: Response
  try {
    response = await fetch(path, init)
  } catch {
    throw new ApiError(0, 'network')
  }

  if (response.status === 204) {
    return undefined as T
  }

  const text = await response.text()
  let data: unknown
  try {
    data = text === '' ? undefined : JSON.parse(text)
  } catch {
    data = undefined
  }

  if (!response.ok) {
    const { error, ...details } = (isRecord(data) ? data : {}) as Record<string, unknown>
    const code = typeof error === 'string' ? error : 'http_error'
    if (SESSION_LOST_CODES.has(code)) {
      sessionLost?.()
    }
    throw new ApiError(response.status, code, details)
  }

  return data as T
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}
