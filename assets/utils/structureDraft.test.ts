import { describe, expect, it } from 'vitest'
import type { TableDetail } from '../api/types'
import {
  addColumn,
  addForeignKey,
  addIndex,
  columnState,
  describeStatement,
  draftFromDetail,
  foreignKeyState,
  indexState,
  isDirty,
  primaryState,
  removeColumn,
  removePrimary,
  restorePrimary,
  removeIndex,
  togglePrimary,
  toPayload,
} from './structureDraft'

const detail = (): TableDetail => ({
  name: 'orders',
  fingerprint: 'f'.repeat(64),
  kind: 'table',
  estimatedRows: 10,
  viewDefinition: null,
  columns: [
    { name: 'id', type: 'integer', nullable: false, default: null, autoIncrement: true, comment: null },
    { name: 'customer_id', type: 'integer', nullable: false, default: null, autoIncrement: false, comment: null },
    { name: 'status', type: 'character varying(20)', nullable: false, default: "'new'::character varying", autoIncrement: false, comment: null },
  ],
  indexes: [
    { name: 'orders_pkey', columns: ['id'], unique: true, primary: true },
    { name: 'idx_status', columns: ['status'], unique: false, primary: false },
  ],
  foreignKeys: [{ name: 'fk_customer', columns: ['customer_id'], referencedTable: 'customers', referencedColumns: ['id'], onUpdate: 'no_action', onDelete: 'cascade' }],
})

const col = (draft: ReturnType<typeof draftFromDetail>, name: string) => {
  const found = draft.columns.find((column) => column.name === name)
  if (found === undefined) {
    throw new Error(`no column ${name}`)
  }

  return found
}

describe('draftFromDetail', () => {
  it('copies the table, with the primary key as a property of its columns', () => {
    const draft = draftFromDetail(detail())

    expect(draft.name).toBe('orders')
    expect(draft.columns.map((c) => c.name)).toEqual(['id', 'customer_id', 'status'])
    expect(draft.primary).toEqual([col(draft, 'id').uid])
    expect(draft.indexes.map((i) => i.name)).toEqual(['idx_status'])
    expect(draft.indexes[0]?.columns).toEqual([col(draft, 'status').uid])
    expect(col(draft, 'status').default).toBe("'new'::character varying")
    expect(col(draft, 'id').default).toBe('')
  })

  it('is not dirty until something changes', () => {
    expect(isDirty(draftFromDetail(detail()), detail())).toBe(false)
  })

  it('keeps the sort key of a ClickHouse table as a locked index that is never edited', () => {
    const d = detail()
    d.indexes = [{ name: 'ORDER BY', columns: ['id'], unique: false, primary: false }, { name: 'idx_status', columns: ['status'], unique: false, primary: false }]
    const draft = draftFromDetail(d, true)

    expect(draft.indexes[0]).toMatchObject({ name: 'ORDER BY', locked: true })
    expect(draft.indexes[1]?.locked).toBe(false)
  })
})

describe('addColumn', () => {
  it('starts nullable, unless the engine says otherwise', () => {
    const draft = draftFromDetail(detail())

    expect(addColumn(draft).nullable).toBe(true)
    expect(addColumn(draft, false).nullable).toBe(false)
  })
})

describe('marks', () => {
  it('says what changed in a column, and only that', () => {
    const draft = draftFromDetail(detail())
    const status = col(draft, 'status')
    status.name = 'state'
    status.type = 'text'

    const state = columnState(draft, detail(), status)
    expect(state.status).toBe('changed')
    expect([...state.fields].sort()).toEqual(['name', 'type'])
    expect(columnState(draft, detail(), col(draft, 'id')).status).toBe('same')
  })

  it('does not call a type changed because of case or spaces', () => {
    const draft = draftFromDetail(detail())
    col(draft, 'status').type = 'CHARACTER  VARYING( 20 )'

    expect(columnState(draft, detail(), col(draft, 'status')).status).toBe('same')
  })

  it('marks a new column, and a removed one', () => {
    const draft = draftFromDetail(detail())
    const added = addColumn(draft)
    removeColumn(draft, col(draft, 'status'))

    expect(columnState(draft, detail(), added).status).toBe('new')
    expect(columnState(draft, detail(), col(draft, 'status')).status).toBe('removed')
    expect(isDirty(draft, detail())).toBe(true)
  })

  it('forgets a new column that is removed, and keeps an existing one struck out', () => {
    const draft = draftFromDetail(detail())
    const added = addColumn(draft)

    removeColumn(draft, added)
    expect(draft.columns).toHaveLength(3)

    removeColumn(draft, col(draft, 'status'))
    expect(draft.columns).toHaveLength(3)
    expect(col(draft, 'status').removed).toBe(true)
  })

  it('marks a primary key change on the column', () => {
    const draft = draftFromDetail(detail())
    togglePrimary(draft, col(draft, 'customer_id'))

    expect(columnState(draft, detail(), col(draft, 'customer_id')).fields.has('primary')).toBe(true)
    expect(isDirty(draft, detail())).toBe(true)
  })

  it('marks indexes and foreign keys the same way', () => {
    const draft = draftFromDetail(detail())
    const fresh = addIndex(draft)
    const fk = addForeignKey(draft)
    removeIndex(draft, draft.indexes[0]!)

    expect(indexState(draft, detail(), fresh).status).toBe('new')
    expect(indexState(draft, detail(), draft.indexes[0]!).status).toBe('removed')
    expect(foreignKeyState(draft, detail(), fk).status).toBe('new')
    expect(foreignKeyState(draft, detail(), draft.foreignKeys[0]!).status).toBe('same')
    draft.foreignKeys[0]!.onDelete = 'restrict'
    expect(foreignKeyState(draft, detail(), draft.foreignKeys[0]!).status).toBe('changed')
  })

  it('does not call an index changed when only a column it uses was renamed', () => {
    const draft = draftFromDetail(detail())
    col(draft, 'status').name = 'state'

    expect(indexState(draft, detail(), draft.indexes[0]!).status).toBe('same')
  })

  it('renaming the table makes the draft dirty', () => {
    const draft = draftFromDetail(detail())
    draft.name = 'sales'

    expect(isDirty(draft, detail())).toBe(true)
  })
})

describe('primary key as an index', () => {
  it('is the same until its columns change', () => {
    const draft = draftFromDetail(detail())

    expect(primaryState(draft, detail()).status).toBe('same')
    togglePrimary(draft, col(draft, 'customer_id'))
    expect(primaryState(draft, detail()).status).toBe('changed')
  })

  it('is removed when emptied, and comes back as it was when restored', () => {
    const draft = draftFromDetail(detail())

    removePrimary(draft)
    expect(draft.primary).toEqual([])
    expect(primaryState(draft, detail()).status).toBe('removed')
    expect(toPayload(draft).indexes.some((i) => i.primary)).toBe(false)

    restorePrimary(draft)
    expect(primaryState(draft, detail()).status).toBe('same')
    expect(isDirty(draft, detail())).toBe(false)
  })

  it('is removed too when all its columns are removed from the table', () => {
    const draft = draftFromDetail(detail())
    removeColumn(draft, col(draft, 'id'))

    expect(primaryState(draft, detail()).status).toBe('removed')
  })

  it('is new when a table without one gets one', () => {
    const d = detail()
    d.indexes = d.indexes.filter((index) => !index.primary)
    const draft = draftFromDetail(d)

    expect(primaryState(draft, d).status).toBe('same')
    togglePrimary(draft, col(draft, 'id'))
    expect(primaryState(draft, d).status).toBe('new')
  })
})

describe('toPayload', () => {
  it('turns an untouched draft into exactly the table it came from, with original names', () => {
    const payload = toPayload(draftFromDetail(detail()))

    expect(payload.name).toBe('orders')
    expect(payload.columns).toEqual([
      { originalName: 'id', name: 'id', type: 'integer', nullable: false, default: null, autoIncrement: true },
      { originalName: 'customer_id', name: 'customer_id', type: 'integer', nullable: false, default: null, autoIncrement: false },
      { originalName: 'status', name: 'status', type: 'character varying(20)', nullable: false, default: "'new'::character varying", autoIncrement: false },
    ])
    expect(payload.indexes).toEqual([
      { originalName: 'idx_status', name: 'idx_status', columns: ['status'], unique: false, primary: false, kind: null, granularity: null },
      { originalName: 'orders_pkey', name: 'orders_pkey', columns: ['id'], unique: true, primary: true, kind: null, granularity: null },
    ])
    expect(payload.foreignKeys).toEqual([{ originalName: 'fk_customer', name: 'fk_customer', columns: ['customer_id'], referencedTable: 'customers', referencedColumns: ['id'], onUpdate: 'no_action', onDelete: 'cascade' }])
  })

  it('leaves out removed columns, and removes them from the indexes that used them', () => {
    const draft = draftFromDetail(detail())
    removeColumn(draft, col(draft, 'status'))
    const payload = toPayload(draft)

    expect(payload.columns.map((c) => c.name)).toEqual(['id', 'customer_id'])
    expect(payload.indexes.map((i) => i.name)).toEqual(['orders_pkey'])
  })

  it('follows a renamed column in the indexes, the primary key and the foreign keys', () => {
    const draft = draftFromDetail(detail())
    col(draft, 'id').name = 'order_id'
    col(draft, 'customer_id').name = 'buyer_id'
    const payload = toPayload(draft)

    expect(payload.indexes.find((i) => i.primary)?.columns).toEqual(['order_id'])
    expect(payload.foreignKeys[0]?.columns).toEqual(['buyer_id'])
  })

  it('writes an empty default as no default, and trims it', () => {
    const draft = draftFromDetail(detail())
    col(draft, 'status').default = '   '

    expect(toPayload(draft).columns[2]?.default).toBeNull()
  })

  it('adds a new primary key column in table order after the existing ones', () => {
    const draft = draftFromDetail(detail())
    togglePrimary(draft, col(draft, 'status'))
    togglePrimary(draft, col(draft, 'customer_id'))

    expect(toPayload(draft).indexes.find((i) => i.primary)?.columns).toEqual(['id', 'status', 'customer_id'])
  })

  it('has no primary key left when every primary column is removed or unticked', () => {
    const draft = draftFromDetail(detail())
    togglePrimary(draft, col(draft, 'id'))

    expect(toPayload(draft).indexes.some((i) => i.primary)).toBe(false)
  })

  it('sends a new column, index and foreign key without original name', () => {
    const draft = draftFromDetail(detail())
    const column = addColumn(draft)
    column.name = 'note'
    column.type = 'text'
    const index = addIndex(draft)
    index.name = 'idx_note'
    index.columns = [column.uid]
    const fk = addForeignKey(draft)
    fk.name = 'fk_note'
    fk.columns = [column.uid]
    fk.referencedTable = 'notes'
    fk.referencedColumns = ['id']
    const payload = toPayload(draft)

    expect(payload.columns[3]).toMatchObject({ originalName: null, name: 'note', type: 'text', nullable: true })
    expect(payload.indexes.find((i) => i.name === 'idx_note')).toMatchObject({ originalName: null, columns: ['note'] })
    expect(payload.foreignKeys[1]).toMatchObject({ originalName: null, name: 'fk_note', columns: ['note'] })
  })

  it('keeps a locked index with its raw columns', () => {
    const d = detail()
    d.indexes = [{ name: 'ORDER BY', columns: ['id'], unique: false, primary: false }]
    const draft = draftFromDetail(d, true)

    expect(toPayload(draft).indexes).toEqual([{ originalName: 'ORDER BY', name: 'ORDER BY', columns: ['id'], unique: false, primary: false, kind: null, granularity: null }])
  })
})

describe('describeStatement', () => {
  it('says in French what a statement does', () => {
    expect(describeStatement('drop_column', 'note')).toBe('Retirer la colonne « note »')
    expect(describeStatement('add_column', 'qty')).toBe('Ajouter la colonne « qty »')
    expect(describeStatement('modify_column', 'status')).toBe('Modifier la colonne « status »')
    expect(describeStatement('drop_table', 'orders')).toBe('Supprimer la table « orders »')
    expect(describeStatement('materialize_index', 'idx')).toBe('Matérialiser l’index « idx » sur les données existantes')
    expect(describeStatement('unknown_thing', 'x')).toBe('unknown_thing « x »')
  })
})
