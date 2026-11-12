import { describe, expect, it } from 'vitest'
import type { SecurityFinding } from '../api/types'
import { describeFinding, describeEncryption, severityLabel } from './accounts'

const finding = (overrides: Partial<SecurityFinding>): SecurityFinding => ({ rule: 'wildcard_host', severity: 'medium', account: 'app@%', detail: null, currentAccount: false, ...overrides })

describe('describeFinding', () => {
  it('names the privilege a dangerous account holds', () => {
    expect(describeFinding(finding({ rule: 'dangerous_privilege', detail: 'FILE (via lecture)' }))).toContain('FILE (via lecture)')
  })

  it('says the account of the connection is the last line of defence', () => {
    expect(describeFinding(finding({ rule: 'dangerous_privilege', detail: 'SUPER', currentAccount: true }))).toContain('compte de cette connexion')
  })

  it('has a sentence for every rule the server can send', () => {
    const rules = ['anonymous_account', 'wildcard_host', 'same_name_many_hosts', 'no_password', 'legacy_plugin', 'disabled_plugin', 'password_expired', 'dangerous_privilege', 'all_privileges', 'grant_option', 'role_never_active', 'no_tls_requirement', 'open_test_database']
    for (const rule of rules) {
      expect(describeFinding(finding({ rule, detail: 'x' }))).not.toBe('')
      expect(describeFinding(finding({ rule, detail: 'x' }))).not.toContain(rule)
    }
  })

  it('falls back to the code for a rule it does not know yet', () => {
    expect(describeFinding(finding({ rule: 'brand_new' }))).toContain('brand_new')
  })
})

describe('severityLabel', () => {
  it('translates the three levels', () => {
    expect([severityLabel('high'), severityLabel('medium'), severityLabel('low')]).toEqual(['Élevée', 'Moyenne', 'Faible'])
  })
})

describe('describeEncryption', () => {
  it('says the connection is encrypted with the protocol and the cipher', () => {
    expect(describeEncryption({ status: { availability: 'available', reason: null, privilege: null }, encrypted: true, protocol: 'TLSv1.3', cipher: 'TLS_AES_256_GCM_SHA384' })).toBe('Connexion chiffrée (TLSv1.3, TLS_AES_256_GCM_SHA384).')
  })

  it('warns when it is not', () => {
    expect(describeEncryption({ status: { availability: 'available', reason: null, privilege: null }, encrypted: false, protocol: null, cipher: null })).toContain('n’est pas chiffrée')
  })
})
