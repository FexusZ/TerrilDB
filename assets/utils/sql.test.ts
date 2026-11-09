import { describe, expect, it } from 'vitest'
import { isPlainReadOf, previewQuery, quoteIdentifier } from './sql'

describe('sql helpers', () => {
  it('quotes identifiers the way each engine reads them', () => {
    expect(quoteIdentifier('postgresql', 'orders')).toBe('"orders"')
    expect(quoteIdentifier('postgresql', 'we"ird')).toBe('"we""ird"')
    expect(quoteIdentifier('mysql', 'orders')).toBe('`orders`')
    expect(quoteIdentifier('mariadb', 'we`ird')).toBe('`we``ird`')
  })

  it('quotes ClickHouse identifiers with backticks and backslash escapes', () => {
    expect(quoteIdentifier('clickhouse', 'orders')).toBe('`orders`')
    expect(quoteIdentifier('clickhouse', 'we`ird')).toBe('`we\\`ird`')
    expect(quoteIdentifier('clickhouse', 'back\\slash')).toBe('`back\\\\slash`')
  })

  it('builds a bounded preview of a table', () => {
    expect(previewQuery('postgresql', 'orders')).toBe('SELECT *\n  FROM "orders"\n LIMIT 100;')
    expect(previewQuery('mysql', 'orders', 25)).toBe('SELECT *\n  FROM `orders`\n LIMIT 25;')
  })
})

describe('isPlainReadOf', () => {
  it('accepts a plain read of the table, however it is quoted, filtered, ordered or limited', () => {
    expect(isPlainReadOf('SELECT *\n  FROM "orders"\n LIMIT 100;', 'orders')).toBe(true)
    expect(isPlainReadOf('select id, status from orders where status = \'paid\' order by id desc limit 10', 'orders')).toBe(true)
    expect(isPlainReadOf('SELECT * FROM `shop`.`orders` WHERE id > 3', 'orders')).toBe(true)
    expect(isPlainReadOf('SELECT * FROM public."orders" o WHERE o.id = 1', 'orders')).toBe(true)
    expect(isPlainReadOf('-- les commandes\nSELECT * FROM orders /* tout */', 'orders')).toBe(true)
  })

  it('refuses a read of another table', () => {
    expect(isPlainReadOf('SELECT id, name FROM customers', 'orders')).toBe(false)
    expect(isPlainReadOf('SELECT * FROM orders_archive', 'orders')).toBe(false)
  })

  it('refuses what does not return the rows of the table as they are', () => {
    expect(isPlainReadOf('SELECT * FROM orders o JOIN customers c ON c.id = o.customer_id', 'orders')).toBe(false)
    expect(isPlainReadOf('SELECT * FROM orders, customers', 'orders')).toBe(false)
    expect(isPlainReadOf('SELECT status, count(*) FROM orders GROUP BY status', 'orders')).toBe(false)
    expect(isPlainReadOf('SELECT DISTINCT status FROM orders', 'orders')).toBe(false)
    expect(isPlainReadOf('SELECT * FROM orders UNION SELECT * FROM orders', 'orders')).toBe(false)
    expect(isPlainReadOf('SELECT * FROM orders WHERE id IN (SELECT id FROM orders)', 'orders')).toBe(false)
    expect(isPlainReadOf('WITH x AS (SELECT 1) SELECT * FROM orders', 'orders')).toBe(false)
  })

  it('ignores keywords inside strings and comments', () => {
    expect(isPlainReadOf("SELECT * FROM orders WHERE note = 'join group by from x'", 'orders')).toBe(true)
  })

  it('refuses anything that is not a SELECT', () => {
    expect(isPlainReadOf('DELETE FROM orders', 'orders')).toBe(false)
    expect(isPlainReadOf('', 'orders')).toBe(false)
  })
})
