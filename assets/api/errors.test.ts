import { describe, expect, it } from 'vitest'
import { ApiError } from './client'
import { messageFor, reasonText } from './errors'

describe('error messages', () => {
  it('does not show the engine message when a diagnostic read fails', () => {
    expect(messageFor(new ApiError(502, 'diagnostics_failed', { message: 'secret host' }))).toBe('La lecture de l’activité du serveur a échoué. Réessayez.')
  })

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

  it('explains that an address of the server itself is reserved to administrators', () => {
    const message = messageFor(new ApiError(403, 'forbidden_target'))
    expect(message).toContain('serveur')
    expect(message).toContain('administrateur')
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

  it('explains the refusals of the structure editor', () => {
    expect(messageFor(new ApiError(422, 'invalid_schema_change', { detail: 'A column needs a type.' }))).toContain('invalide')
    expect(messageFor(new ApiError(409, 'schema_changed'))).toContain('modifiée')
    expect(messageFor(new ApiError(409, 'schema_change_failed', { message: 'column "x" does not exist' }))).toBe('column "x" does not exist')
    expect(messageFor(new ApiError(409, 'data_changed', { statement: 0, matched: 0 }))).toContain('modifiée')
    expect(messageFor(new ApiError(409, 'data_change_failed', { message: 'duplicate key' }))).toBe('duplicate key')
    expect(messageFor(new ApiError(409, 'data_change_failed'))).toContain('refusé')
    expect(messageFor(new ApiError(422, 'invalid_data_change', { detail: 'The column "x" does not exist.' }))).toContain('invalide')
    expect(messageFor(new ApiError(409, 'schema_change_failed'))).toContain('refusé')
    expect(messageFor(new ApiError(409, 'confirmation_required', { reason: 'destructive_schema_change' }))).toContain('détruit')
  })

  it('gives advice for lock conflicts without echoing anything from the engine', () => {
    const deadlock = messageFor(new ApiError(409, 'deadlock', { retryable: true, message: "value 'jean@example.org'" }))
    expect(deadlock).toContain('interblocage')
    expect(deadlock).toContain('Relancez')
    expect(deadlock).not.toContain('jean')
    const timeout = messageFor(new ApiError(409, 'lock_timeout', { retryable: true }))
    expect(timeout).toContain('verrou')
    expect(timeout).not.toBe(deadlock)
    const serialization = messageFor(new ApiError(409, 'serialization_failure', { retryable: true, message: "value 'jean@example.org'" }))
    expect(serialization).toContain('simultanée')
    expect(serialization).toContain('Relancez')
    expect(serialization).not.toContain('jean')
    expect(serialization).not.toContain('interblocage')
  })

  it('does not advise replaying when part of the change is already applied', () => {
    const message = messageFor(new ApiError(409, 'lock_timeout', { retryable: false, applied: [{ sql: 'ALTER TABLE t ADD c int' }] }))
    expect(message).toContain('déjà appliquées')
    expect(message).not.toContain('Relancez')
    expect(messageFor(new ApiError(409, 'deadlock', { retryable: false, applied: [{ sql: 'x' }] }))).toContain('déjà appliquées')
  })

  it('names the refused statement of a script and says nothing ran', () => {
    const message = messageFor(new ApiError(403, 'script_refused', { reason: 'forbidden_construct', statement: 2 }))

    expect(message).toContain('instruction 3')
    expect(message).toContain('Rien n’a été exécuté')
    expect(message).toContain('interdite')
  })

  it('explains a malformed, oversized or untestable script', () => {
    expect(messageFor(new ApiError(422, 'invalid_script'))).toContain('chaîne')
    expect(messageFor(new ApiError(422, 'script_too_large'))).toContain('500')
    expect(messageFor(new ApiError(409, 'dry_run_unavailable', { reason: 'implicit_commit' }))).toContain('valide')
    expect(messageFor(new ApiError(409, 'dry_run_unavailable', { reason: 'no_transaction' }))).toContain('transaction')
    expect(messageFor(new ApiError(409, 'dry_run_unavailable', { reason: 'non_transactional_tables' }))).toContain('sans transaction')
    expect(reasonText('destructive_script')).toContain('confirm')
  })
})
