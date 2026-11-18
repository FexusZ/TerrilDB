import { describe, expect, it } from 'vitest'
import { lockSentence, lockShort } from './lock'

describe('lock labels', () => {
  it('names the period columns of a versioned table', () => {
    expect(lockShort('system_period')).toBe('colonne de période')
    expect(lockSentence('system_period')).toContain('versionnée')
  })
})
