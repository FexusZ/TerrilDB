import { describe, expect, it } from 'vitest'
import { lossSentence } from './planLoss'

describe('lossSentence', () => {
  it('says that dropping a versioned table drops its history', () => {
    expect(lossSentence({ code: 'system_history', subject: 'orders', values: [] })).toBe('l’historique des lignes de « orders » (table versionnée)')
  })

  it('lists the values removed from an ENUM or SET', () => {
    expect(lossSentence({ code: 'values_removed', subject: 'kind', values: ['z', 'w'] })).toBe('les valeurs « z », « w » de « kind » : les lignes qui les portent bloqueront le changement ou les perdront')
  })

  it('stays readable for a code it does not know', () => {
    expect(lossSentence({ code: 'new_thing', subject: 'x', values: [] })).toBe('une perte sur « x »')
  })
})
