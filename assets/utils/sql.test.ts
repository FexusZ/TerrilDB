import { describe, expect, it } from 'vitest'
import { previewQuery, quoteIdentifier } from './sql'

describe('sql helpers', () => {
  it('quotes identifiers the way each engine reads them', () => {
    expect(quoteIdentifier('postgresql', 'orders')).toBe('"orders"')
    expect(quoteIdentifier('postgresql', 'we"ird')).toBe('"we""ird"')
    expect(quoteIdentifier('mysql', 'orders')).toBe('`orders`')
    expect(quoteIdentifier('mariadb', 'we`ird')).toBe('`we``ird`')
  })

  it('builds a bounded preview of a table', () => {
    expect(previewQuery('postgresql', 'orders')).toBe('SELECT *\n  FROM "orders"\n LIMIT 100;')
    expect(previewQuery('mysql', 'orders', 25)).toBe('SELECT *\n  FROM `orders`\n LIMIT 25;')
  })
})
