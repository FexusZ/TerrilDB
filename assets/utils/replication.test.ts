import { describe, expect, it } from 'vitest'
import type { ReplicationAlert, ReplicationChannel } from '../api/types'
import { alertText, lagNote, roleLabel, threadLabel } from './replication'

const thresholds = { warning: 30, critical: 300 }
const channel = (overrides: Partial<ReplicationChannel> = {}): ReplicationChannel => ({
  name: '', receiver: 'running', applier: 'running', lagSeconds: 0, received: null, applied: null, pendingApply: false, receiverError: null, applierError: null, ...overrides,
})

describe('replication wording', () => {
  it('says plainly when nothing is replicated', () => {
    expect(roleLabel('none')).toBe('Pas de réplication configurée')
    expect(roleLabel('replica')).toBe('Réplica')
    expect(roleLabel('source')).toBe('Source')
  })

  it('names the thread states in French', () => {
    expect(threadLabel('running')).toBe('actif')
    expect(threadLabel('connecting')).toBe('connexion en cours')
    expect(threadLabel('stopped')).toBe('arrêté')
  })

  it('gives a sentence per alert, with the thresholds', () => {
    const alert = (code: string): ReplicationAlert => ({ code, severity: 'warning', channel: '' })

    expect(alertText(alert('lag_high'), thresholds)).toContain('30 s')
    expect(alertText(alert('lag_critical'), thresholds)).toContain('5 min')
    expect(alertText(alert('applier_error'), thresholds)).toContain('rien n’est exécuté')
    expect(alertText(alert('writable_replica'), thresholds)).toContain('écritures')
  })

  it('never shows an unknown alert code as is', () => {
    expect(alertText({ code: '<b>x</b>', severity: 'warning', channel: '' }, thresholds)).not.toContain('<b>')
  })

  it('names the channel of an alert when there is one', () => {
    expect(alertText({ code: 'receiver_stopped', severity: 'critical', channel: 'east' }, thresholds)).toContain('east')
  })

  it('warns that a lag of zero can hide a receiver ahead of the applier', () => {
    expect(lagNote(channel({ lagSeconds: 0, pendingApply: true }))).toContain('trompeur')
    expect(lagNote(channel({ lagSeconds: 0, pendingApply: false }))).toBeNull()
    expect(lagNote(channel({ lagSeconds: 12, pendingApply: true }))).toBeNull()
  })
})
