import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import type { TableDetail } from '../api/types'
import TableStructure from './TableStructure.vue'

const table: TableDetail = {
  name: 'order_items',
  kind: 'table',
  estimatedRows: 11420,
  viewDefinition: null,
  columns: [
    { name: 'id', type: 'integer', nullable: false, default: null, autoIncrement: true, comment: null },
    { name: 'order_id', type: 'integer', nullable: false, default: null, autoIncrement: false, comment: null },
    { name: 'note', type: 'text', nullable: true, default: "'n/a'", autoIncrement: false, comment: 'Libre' },
  ],
  indexes: [
    { name: 'order_items_pkey', columns: ['id'], unique: true, primary: true },
    { name: 'uq_line', columns: ['order_id', 'line_no'], unique: true, primary: false },
    { name: 'idx_product', columns: ['product_id'], unique: false, primary: false },
  ],
  foreignKeys: [{ name: 'fk_order', columns: ['order_id'], referencedTable: 'orders', referencedColumns: ['id'], onUpdate: 'no_action', onDelete: 'cascade' }],
}

async function mountStructure(value: TableDetail = table) {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/connections/:id/tables/:name(.+)', name: 'table', component: { template: '<p />' } }],
  })
  await router.push('/connections/abc/tables/order_items')

  return mount(TableStructure, { props: { connectionId: 'abc', table: value }, global: { plugins: [router] } })
}

describe('TableStructure', () => {
  it('lists the columns with their native type, nullability and default', async () => {
    const wrapper = await mountStructure()

    const rows = wrapper.findAll('section.columns tbody tr').map((row) => row.findAll('td').map((cell) => cell.text()))
    expect(rows[0]?.slice(0, 4)).toEqual(['id', 'integer', 'non', 'auto-incrément'])
    expect(rows[2]?.slice(0, 4)).toEqual(['note', 'text', 'oui', "'n/a'"])
  })

  it('tags primary and foreign key columns', async () => {
    const wrapper = await mountStructure()

    const rows = wrapper.findAll('section.columns tbody tr')
    expect(rows[0]?.text()).toContain('PK')
    expect(rows[1]?.text()).toContain('FK')
    expect(rows[2]?.text()).not.toContain('PK')
  })

  it('shows the column comment', async () => {
    const wrapper = await mountStructure()

    expect(wrapper.findAll('section.columns tbody tr')[2]?.text()).toContain('Libre')
  })

  it('lists the indexes with their kind', async () => {
    const wrapper = await mountStructure()

    const rows = wrapper.findAll('section.indexes tbody tr').map((row) => row.findAll('td').map((cell) => cell.text()))
    expect(rows).toEqual([
      ['order_items_pkey', 'id', 'clé primaire'],
      ['uq_line', 'order_id, line_no', 'unique'],
      ['idx_product', 'product_id', 'simple'],
    ])
  })

  it('lists the foreign keys, linking to the referenced table', async () => {
    const wrapper = await mountStructure()

    const row = wrapper.get('section.foreign-keys tbody tr')
    expect(row.text()).toContain('fk_order')
    expect(row.text()).toContain('orders (id)')
    expect(row.text()).toContain('suppression en cascade')
    expect(row.get('a').attributes('href')).toBe('/connections/abc/tables/orders')
  })

  it('says when there is no foreign key or no index', async () => {
    const wrapper = await mountStructure({ ...table, indexes: [], foreignKeys: [] })

    expect(wrapper.get('section.indexes').text()).toContain('Aucun index')
    expect(wrapper.get('section.foreign-keys').text()).toContain('Aucune clé étrangère')
  })

  it('shows the definition of a view', async () => {
    const wrapper = await mountStructure({ ...table, kind: 'view', viewDefinition: 'SELECT 1 FROM order_items', indexes: [], foreignKeys: [] })

    expect(wrapper.get('section.definition pre').text()).toBe('SELECT 1 FROM order_items')
  })
})
