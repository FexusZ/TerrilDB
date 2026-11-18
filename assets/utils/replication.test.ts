import { describe, expect, it } from 'vitest'
import type { ReplicationAlert, ReplicationChannel } from '../api/types'
import { alertText, formatBytes, lagNote, roleLabel, semiSyncLabel, threadLabel, walStatusLabel } from './replication'

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

  it('names the peer role of replicated tables', () => {
    expect(roleLabel('peer')).toBe('Tables répliquées entre pairs')
  })

  it('writes sizes in the largest sensible unit', () => {
    expect(formatBytes(0)).toBe('0 o')
    expect(formatBytes(1023)).toBe('1\u202f023 o')
    expect(formatBytes(50331704)).toBe('48 Mio')
    expect(formatBytes(10737418240)).toBe('10 Gio')
    expect(formatBytes(null)).toBe('inconnu')
  })

  it('explains the WAL status of a slot and never shows an unknown one raw', () => {
    expect(walStatusLabel('reserved')).toContain('dans les limites')
    expect(walStatusLabel('unreserved')).toContain('menacé')
    expect(walStatusLabel('lost')).toContain('perdu')
    expect(walStatusLabel(null)).toBe('rien de retenu')
    expect(walStatusLabel('<b>x</b>')).not.toContain('<b>')
  })

  it('names the semi-synchronous modes', () => {
    expect(semiSyncLabel('not_loaded')).toBe('greffon non chargé')
    expect(semiSyncLabel('off')).toBe('désactivé')
    expect(semiSyncLabel('active')).toBe('actif')
    expect(semiSyncLabel('fallen_back')).toContain('asynchrone')
  })

  it('gives a sentence to the new alerts, naming the slot, replica or table', () => {
    const alert = (code: string, channel: string): ReplicationAlert => ({ code, severity: 'warning', channel })

    expect(alertText(alert('slot_inactive', 'orphan'), thresholds)).toContain('retient')
    expect(alertText(alert('slot_inactive', 'orphan'), thresholds)).toContain('slot orphan')
    expect(alertText(alert('slot_wal_lost', 's1'), thresholds)).toContain('perdu')
    expect(alertText(alert('replica_not_streaming', '2'), thresholds)).toContain('réplica 2')
    expect(alertText(alert('table_readonly', 'd.rt'), thresholds)).toContain('table d.rt')
    expect(alertText(alert('table_lag_critical', 'd.rt'), thresholds)).toContain('5 min')
    expect(alertText(alert('binlog_no_expiry', ''), thresholds)).toContain('n’expirent jamais')
    expect(alertText(alert('semisync_fallback', ''), thresholds)).toContain('asynchrone')
    expect(alertText(alert('link_idle', ''), thresholds)).toContain('silence')
  })
})
