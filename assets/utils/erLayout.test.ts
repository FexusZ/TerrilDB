import { describe, expect, it } from 'vitest'
import type { DiagramRelation, DiagramTable } from '../api/types'
import { BOX_WIDTH, HEADER_HEIGHT, ROW_HEIGHT, layoutDiagram } from './erLayout'

function table(name: string, columns: string[] = ['id']): DiagramTable {
  return { name, columns: columns.map((column, index) => ({ name: column, type: 'integer', nullable: false, primary: index === 0 })) }
}

function relation(from: string, column: string, to: string, referenced = 'id'): DiagramRelation {
  return { name: `${from}_${column}_fk`, table: from, columns: [column], referencedTable: to, referencedColumns: [referenced] }
}

describe('layoutDiagram', () => {
  it('sizes each box from its columns', () => {
    const { tables } = layoutDiagram([table('users', ['id', 'name', 'email'])], [])

    expect(tables[0]).toMatchObject({ name: 'users', width: BOX_WIDTH, height: HEADER_HEIGHT + 3 * ROW_HEIGHT })
  })

  it('never overlaps two boxes', () => {
    const names = ['a', 'b', 'c', 'd', 'e', 'f', 'g']
    const { tables } = layoutDiagram(names.map((name) => table(name, ['id', 'x', 'y'])), [])

    for (const [i, one] of tables.entries()) {
      for (const other of tables.slice(i + 1)) {
        const apart = one.x + one.width <= other.x || other.x + other.width <= one.x || one.y + one.height <= other.y || other.y + other.height <= one.y
        expect(apart, `${one.name} / ${other.name}`).toBe(true)
      }
    }
  })

  it('is deterministic whatever the order of the input', () => {
    const tables = [table('c'), table('a'), table('b')]
    const relations = [relation('a', 'c_id', 'c')]

    expect(layoutDiagram([...tables].reverse(), relations)).toEqual(layoutDiagram(tables, relations))
  })

  it('keeps related tables next to each other', () => {
    const tables = ['orders', 'customers', 'logs', 'products', 'order_items', 'audit'].map((name) => table(name))
    const relations = [relation('orders', 'customer_id', 'customers'), relation('order_items', 'order_id', 'orders'), relation('order_items', 'product_id', 'products')]

    const { tables: placed } = layoutDiagram(tables, relations)
    const index = (name: string): number => placed.findIndex((t) => t.name === name)

    expect(Math.abs(index('orders') - index('customers'))).toBe(1)
    expect(Math.abs(index('order_items') - index('orders'))).toBeLessThanOrEqual(2)
    expect(index('logs')).toBeGreaterThan(index('products'))
  })

  it('draws a relation from the foreign key row to the referenced row', () => {
    const { tables, relations } = layoutDiagram(
      [table('customers', ['id', 'name']), table('orders', ['id', 'total', 'customer_id'])],
      [relation('orders', 'customer_id', 'customers')],
    )
    const orders = tables.find((t) => t.name === 'orders')!
    const customers = tables.find((t) => t.name === 'customers')!

    expect(relations).toHaveLength(1)
    const edge = relations[0]!
    expect(edge).toMatchObject({ from: 'orders', to: 'customers' })
    expect(edge.y1).toBe(orders.y + HEADER_HEIGHT + 2 * ROW_HEIGHT + ROW_HEIGHT / 2)
    expect(edge.y2).toBe(customers.y + HEADER_HEIGHT + ROW_HEIGHT / 2)
    expect([orders.x, orders.x + orders.width]).toContain(edge.x1)
    expect([customers.x, customers.x + customers.width]).toContain(edge.x2)
    expect(edge.path.startsWith(`M ${edge.x1} ${edge.y1}`)).toBe(true)
  })

  it('leaves each box on the side facing the other one', () => {
    const { tables, relations } = layoutDiagram([table('a'), table('b', ['id', 'a_id'])], [relation('b', 'a_id', 'a')])
    const a = tables.find((t) => t.name === 'a')!
    const b = tables.find((t) => t.name === 'b')!
    const edge = relations[0]!

    if (a.x < b.x) {
      expect(edge.x1).toBe(b.x)
      expect(edge.x2).toBe(a.x + a.width)
    } else {
      expect(edge.x1).toBe(b.x + b.width)
      expect(edge.x2).toBe(a.x)
    }
  })

  it('draws a self reference as a loop on the right of the box', () => {
    const { tables, relations } = layoutDiagram([table('categories', ['id', 'parent_id'])], [relation('categories', 'parent_id', 'categories')])
    const box = tables[0]!
    const edge = relations[0]!

    expect(edge.x1).toBe(box.x + box.width)
    expect(edge.x2).toBe(box.x + box.width)
    expect(edge.y1).not.toBe(edge.y2)
  })

  it('ignores a relation whose table is not drawn', () => {
    const { relations } = layoutDiagram([table('orders', ['id', 'customer_id'])], [relation('orders', 'customer_id', 'customers')])

    expect(relations).toEqual([])
  })

  it('falls back to the header when the column is unknown', () => {
    const { tables, relations } = layoutDiagram([table('a'), table('b')], [relation('b', 'ghost', 'a', 'ghost')])

    expect(relations[0]!.y1).toBe(tables.find((t) => t.name === 'b')!.y + HEADER_HEIGHT / 2)
  })

  it('returns the size of the whole drawing', () => {
    const result = layoutDiagram([table('a'), table('b'), table('c')], [])

    for (const box of result.tables) {
      expect(box.x + box.width).toBeLessThanOrEqual(result.width)
      expect(box.y + box.height).toBeLessThanOrEqual(result.height)
    }
  })

  it('handles an empty schema', () => {
    expect(layoutDiagram([], [])).toMatchObject({ tables: [], relations: [] })
  })
})
