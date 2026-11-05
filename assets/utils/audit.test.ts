import { describe, expect, it } from 'vitest'
import type { AuditEntry } from '../api/types'
import { ACTIVITY_FILTERS, actorLabel, describeEvent } from './audit'

const entry = (action: string, details: Record<string, unknown> = {}, extra: Partial<AuditEntry> = {}): AuditEntry => ({
  id: '1', occurredAt: '2026-11-01T10:00:00+00:00', action, category: action.split('.')[0] ?? '', outcome: 'success', actor: 'web', ip: null, subjectId: null, tokenId: null, details, ...extra,
})

describe('audit labels', () => {
  it('describes sign-ins and security changes in French', () => {
    expect(describeEvent(entry('login.success'))).toBe('Connexion')
    expect(describeEvent(entry('login.failure', { reason: 'invalid_credentials' }, { outcome: 'failed' }))).toContain('Échec de connexion')
    expect(describeEvent(entry('login.failure', { reason: 'invalid_second_factor' }, { outcome: 'failed' }))).toContain('code')
    expect(describeEvent(entry('login.throttled', {}, { outcome: 'refused' }))).toContain('bloquées')
    expect(describeEvent(entry('password.changed'))).toBe('Mot de passe modifié')
    expect(describeEvent(entry('totp.enabled'))).toContain('activée')
    expect(describeEvent(entry('recovery.used'))).toContain('récupération')
  })

  it('names the connection or token concerned', () => {
    expect(describeEvent(entry('connection.created', { name: 'Production' }))).toBe('Connexion « Production » créée')
    expect(describeEvent(entry('connection.deleted', { name: 'Blog' }))).toBe('Connexion « Blog » supprimée')
    expect(describeEvent(entry('token.created', { name: 'Assistant' }))).toBe('Jeton « Assistant » créé')
    expect(describeEvent(entry('token.revoked'))).toBe('Jeton révoqué')
    expect(describeEvent(entry('token.refreshed', { name: 'Assistant' }))).toBe('Jeton « Assistant » actualisé')
    expect(describeEvent(entry('phrase.created'))).toBe('Phrase de récupération créée')
    expect(describeEvent(entry('phrase.removed'))).toBe('Phrase de récupération retirée')
    expect(describeEvent(entry('phrase.used'))).toBe('Mot de passe réinitialisé avec la phrase de récupération')
    expect(describeEvent(entry('phrase.failed'))).toBe('Phrase de récupération refusée')
    expect(describeEvent(entry('invitation.created'))).toBe('Invitation créée')
    expect(describeEvent(entry('invitation.revoked'))).toBe('Invitation révoquée')
  })

  it('tells a query that ran from one that was refused or failed', () => {
    expect(describeEvent(entry('query.executed'))).toBe('Requête exécutée')
    expect(describeEvent(entry('query.refused', { reason: 'not_read_only' }, { outcome: 'refused' }))).toContain('refusée')
    expect(describeEvent(entry('query.failed', { reason: 'timeout' }, { outcome: 'failed' }))).toContain('délai')
    expect(describeEvent(entry('query.failed', { reason: 'engine_error' }, { outcome: 'failed' }))).toContain('erreur')
  })

  it('does not break on an action it does not know', () => {
    expect(describeEvent(entry('something.new'))).toBe('something.new')
  })

  it('names the door an action came through', () => {
    expect(actorLabel('web')).toBe('Interface')
    expect(actorLabel('api')).toBe('API')
    expect(actorLabel('mcp')).toBe('MCP')
    expect(actorLabel('system')).toBe('Système')
  })

  it('offers filters that match the server categories', () => {
    expect(ACTIVITY_FILTERS.map((filter) => filter.value)).toEqual(['', 'login', 'password', 'totp', 'phrase', 'connection', 'token', 'invitation', 'query'])
  })
})
