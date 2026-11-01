import { describe, expect, it } from 'vitest'
import { ApiError } from './client'
import { messageFor, reasonText } from './errors'

describe('error messages', () => {
  it('speaks French for the stable codes', () => {
    expect(messageFor(new ApiError(401, 'invalid_credentials'))).toBe('Identifiant ou mot de passe incorrect.')
    expect(messageFor(new ApiError(401, 'invalid_second_factor'))).toContain('Code')
    expect(messageFor(new ApiError(502, 'target_unreachable'))).toContain('joindre')
    expect(messageFor(new ApiError(409, 'connection_name_used'))).toContain('nom')
    expect(messageFor(new ApiError(401, 'locked'))).toContain('reconnect')
  })

  it('explains a refused invitation and a forbidden action', () => {
    expect(messageFor(new ApiError(403, 'invalid_invitation'))).toContain('invitation')
    expect(messageFor(new ApiError(403, 'forbidden'))).toContain('administrateur')
    expect(messageFor(new ApiError(409, 'mail_disabled'))).toContain('e-mail')
    expect(messageFor(new ApiError(403, 'invalid_recovery'))).toContain('phrase')
  })

  it('says when a plan is not available for the engine', () => {
    expect(messageFor(new ApiError(501, 'plan_unavailable'))).toContain('plan')
  })

  it('says how long to wait when throttled', () => {
    expect(messageFor(new ApiError(429, 'too_many_attempts', { retryAfter: 42 }))).toContain('42')
  })

  it('shows the engine message of a failed query', () => {
    expect(messageFor(new ApiError(400, 'query_failed', { message: 'syntax error at or near "selec"' }))).toBe('syntax error at or near "selec"')
  })

  it('explains why a query was refused', () => {
    expect(messageFor(new ApiError(403, 'query_refused', { reason: 'not_read_only' }))).toBe(reasonText('not_read_only'))
    expect(reasonText('not_read_only')).toContain('lecture seule')
    expect(reasonText('multiple_statements')).toContain('une seule')
  })

  it('falls back to a generic message for anything else', () => {
    expect(messageFor(new ApiError(500, 'something_new'))).toBe('Une erreur est survenue. Réessayez.')
    expect(messageFor(new Error('boom'))).toBe('Une erreur est survenue. Réessayez.')
    expect(messageFor(new ApiError(0, 'network'))).toContain('serveur')
  })

  it('never shows an unknown refusal reason raw', () => {
    expect(reasonText('brand_new_reason')).toBe('Cette instruction n’est pas autorisée.')
  })
})
