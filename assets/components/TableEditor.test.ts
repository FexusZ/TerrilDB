import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError, api } from '../api/client'
import type { Engine, StructurePreview, TableDetail } from '../api/types'
import TableEditor from './TableEditor.vue'

vi.mock('../api/client', async (importOriginal) => ({ ...(await importOriginal<typeof import('../api/client')>()), api: vi.fn() }))

const mocked = vi.mocked(api)

const detail = (): TableDetail => ({
  name: 'orders',
  fingerprint: 'f'.repeat(64),
  kind: 'table',
  estimatedRows: 10,
  viewDefinition: null,
  columns: [
    { name: 'id', type: 'integer', nullable: false, default: null, autoIncrement: true, comment: null },
    { name: 'status', type: 'character varying(20)', nullable: false, default: "'new'::character varying", autoIncrement: false, comment: null },
    { name: 'note', type: 'text', nullable: true, default: null, autoIncrement: false, comment: null },
  ],
  indexes: [
    { name: 'orders_pkey', columns: ['id'], unique: true, primary: true },
    { name: 'idx_status', columns: ['status'], unique: false, primary: false },
  ],
  foreignKeys: [{ name: 'fk_customer', columns: ['status'], referencedTable: 'customers', referencedColumns: ['code'], onUpdate: 'no_action', onDelete: 'cascade' }],
})

const preview = (over: Partial<StructurePreview> = {}): StructurePreview => ({
  statements: [{ sql: 'ALTER TABLE "orders" ALTER COLUMN "note" TYPE varchar(80) USING "note"::varchar(80)', operation: 'modify_column', subject: 'note', destructive: false }],
  transactional: true,
  destructive: false,
  fingerprint: 'f'.repeat(64),
  stale: false,
  ...over,
})

function mountEditor(engine: Engine = 'postgresql', table: TableDetail = detail()): VueWrapper {
  const pinia = createPinia()
  setActivePinia(pinia)

  return mount(TableEditor, { props: { connectionId: 'abc', engine, table, scope: { database: null, schema: null } }, global: { plugins: [pinia] }, attachTo: document.body })
}

/** Choisit une option d'une liste par son libellé (les valeurs sont des identifiants internes). */
async function pick(select: ReturnType<VueWrapper['get']>, label: string): Promise<void> {
  const option = select.findAll('option').find((candidate) => candidate.text() === label)
  if (option === undefined) {
    throw new Error(`no option ${label}`)
  }
  await select.setValue((option.element as HTMLOptionElement).value)
}

/** Le libellé de l'option choisie dans une liste. */
function selected(select: ReturnType<VueWrapper['get']>): string | undefined {
  const element = select.element as HTMLSelectElement

  return element.options[element.selectedIndex]?.text
}

async function typeInto(wrapper: VueWrapper, selector: string, value: string): Promise<void> {
  await wrapper.get(selector).setValue(value)
}

/** Laisse passer le délai d'attente avant l'aperçu (frappe) et la réponse. */
async function settle(): Promise<void> {
  await vi.advanceTimersByTimeAsync(400)
  await flushPromises()
}

const structureCalls = (action: 'preview' | 'apply') => mocked.mock.calls.filter(([, url]) => String(url).endsWith(`/structure/${action}`))

describe('TableEditor', () => {
  beforeEach(() => {
    mocked.mockReset()
    vi.useFakeTimers()
  })
  afterEach(() => vi.useRealTimers())

  it('shows the table as editable fields, and no change bar until something changes', async () => {
    const wrapper = mountEditor()

    expect(wrapper.findAll('.columns tbody tr')).toHaveLength(3)
    expect((wrapper.findAll('input[aria-label="Nom de la colonne"]')[1]?.element as HTMLInputElement).value).toBe('status')
    expect(wrapper.find('[aria-label="Modifications en attente"]').exists()).toBe(false)
    expect(structureCalls('preview')).toHaveLength(0)
    wrapper.unmount()
  })

  it('shows the SQL on top as soon as a field changes, highlights what changed, and writes nothing yet', async () => {
    mocked.mockResolvedValue(preview())
    const wrapper = mountEditor()

    await wrapper.findAll('input[aria-label="Type de la colonne"]')[2]?.setValue('varchar(80)')
    await settle()

    expect(wrapper.find('[aria-label="Modifications en attente"]').exists()).toBe(true)
    expect(wrapper.get('[aria-label="SQL qui sera exécuté"]').text()).toContain('ALTER TABLE "orders" ALTER COLUMN "note" TYPE varchar(80)')
    expect(wrapper.findAll('.columns tbody tr')[2]?.attributes('data-status')).toBe('changed')
    expect(wrapper.findAll('input[aria-label="Type de la colonne"]')[2]?.classes()).toContain('edited')
    expect(wrapper.findAll('.columns tbody tr')[0]?.attributes('data-status')).toBe('same')
    expect(structureCalls('apply')).toHaveLength(0)
    const [, , body] = structureCalls('preview')[0] ?? []
    expect(body).toMatchObject({ fingerprint: 'f'.repeat(64), draft: { name: 'orders' } })
    wrapper.unmount()
  })

  it('says whether the changes are applied all at once or one by one', async () => {
    mocked.mockResolvedValue(preview({ transactional: false }))
    const wrapper = mountEditor('mysql')

    await wrapper.findAll('input[aria-label="Type de la colonne"]')[2]?.setValue('varchar(80)')
    await settle()

    expect(wrapper.get('.mode').text()).toContain('instruction par instruction')
    wrapper.unmount()
  })

  it('waits for a new column to have a name and a type before asking for the SQL', async () => {
    mocked.mockResolvedValue(preview())
    const wrapper = mountEditor()

    await wrapper.findAll('button').find((button) => button.text().includes('Ajouter une colonne'))?.trigger('click')
    await settle()

    expect(wrapper.text()).toContain('Complétez les lignes nouvelles')
    expect(structureCalls('preview')).toHaveLength(0)
    expect(wrapper.findAll('.columns tbody tr')[3]?.attributes('data-status')).toBe('new')

    await wrapper.findAll('input[aria-label="Nom de la colonne"]')[3]?.setValue('qty')
    await wrapper.findAll('input[aria-label="Type de la colonne"]')[3]?.setValue('integer')
    await settle()

    expect(structureCalls('preview')).toHaveLength(1)
    wrapper.unmount()
  })

  it('applies a change that destroys nothing straight away and hands the new table back', async () => {
    const after = { ...detail(), fingerprint: 'a'.repeat(64) }
    mocked.mockImplementation((_method?: string, url = ''): Promise<unknown> => Promise.resolve(url.endsWith('/preview') ? preview() : { ...preview(), table: after }))
    const wrapper = mountEditor()
    await wrapper.findAll('input[aria-label="Type de la colonne"]')[2]?.setValue('varchar(80)')
    await settle()

    await wrapper.findAll('button').find((button) => button.text() === 'Valider les changements')?.trigger('click')
    await flushPromises()

    const [, , body] = structureCalls('apply')[0] ?? []
    expect(body).toMatchObject({ confirmed: false, fingerprint: 'f'.repeat(64) })
    expect(wrapper.emitted('applied')?.[0]).toEqual([after, 'orders'])
    wrapper.unmount()
  })

  it('asks for a confirmation that lists what will be lost, and applies only after it', async () => {
    const drop = { sql: 'ALTER TABLE "orders" DROP COLUMN "note"', operation: 'drop_column', subject: 'note', destructive: true }
    mocked.mockImplementation((_method?: string, url = ''): Promise<unknown> => Promise.resolve(url.endsWith('/preview') ? preview({ statements: [drop], destructive: true }) : { ...preview({ statements: [drop], destructive: true }), table: detail() }))
    const wrapper = mountEditor()
    await wrapper.findAll('button[aria-label^="Retirer la colonne"]')[2]?.trigger('click')
    await settle()
    expect(wrapper.findAll('.columns tbody tr')[2]?.attributes('data-status')).toBe('removed')
    expect(wrapper.get('[aria-label="SQL qui sera exécuté"]').text()).toContain('détruit')

    await wrapper.findAll('button').find((button) => button.text() === 'Valider les changements')?.trigger('click')
    await flushPromises()

    expect(structureCalls('apply')).toHaveLength(0)
    expect(wrapper.get('[role="dialog"]').text()).toContain('Retirer la colonne « note »')

    await wrapper.get('.confirm').trigger('click')
    await flushPromises()

    expect(structureCalls('apply')).toHaveLength(1)
    expect(structureCalls('apply')[0]?.[2]).toMatchObject({ confirmed: true })
    wrapper.unmount()
  })

  it('cancels every change in one click', async () => {
    mocked.mockResolvedValue(preview())
    const wrapper = mountEditor()
    await wrapper.findAll('input[aria-label="Type de la colonne"]')[2]?.setValue('varchar(80)')
    await settle()

    await wrapper.findAll('button').find((button) => button.text() === 'Annuler')?.trigger('click')
    await settle()

    expect(wrapper.find('[aria-label="Modifications en attente"]').exists()).toBe(false)
    expect((wrapper.findAll('input[aria-label="Type de la colonne"]')[2]?.element as HTMLInputElement).value).toBe('text')
    wrapper.unmount()
  })

  it('restores a column that was removed', async () => {
    mocked.mockResolvedValue(preview())
    const wrapper = mountEditor()
    await wrapper.findAll('button[aria-label^="Retirer la colonne"]')[2]?.trigger('click')

    await wrapper.findAll('button').find((button) => button.text() === 'Restaurer')?.trigger('click')
    await settle()

    expect(wrapper.findAll('.columns tbody tr')[2]?.attributes('data-status')).toBe('same')
    expect(wrapper.find('[aria-label="Modifications en attente"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('refuses to validate when the table changed in the meantime, and offers to reload it', async () => {
    mocked.mockResolvedValue(preview({ stale: true }))
    const wrapper = mountEditor()
    await wrapper.findAll('input[aria-label="Type de la colonne"]')[2]?.setValue('varchar(80)')
    await settle()

    const validate = wrapper.findAll('button').find((button) => button.text() === 'Valider les changements')
    expect(validate?.attributes('disabled')).toBeDefined()
    expect(wrapper.text()).toContain('La table a changé')

    await wrapper.findAll('button').find((button) => button.text() === 'Recharger la table')?.trigger('click')
    expect(wrapper.emitted('reload')).toHaveLength(1)
    wrapper.unmount()
  })

  it('explains an invalid draft with the reason the server gives', async () => {
    mocked.mockRejectedValue(new ApiError(422, 'invalid_schema_change', { detail: 'The type contains a word that is not part of a type.' }))
    const wrapper = mountEditor()

    await wrapper.findAll('input[aria-label="Type de la colonne"]')[2]?.setValue('int PRIMARY KEY')
    await settle()

    expect(wrapper.get('[role="alert"]').text()).toContain('invalide')
    expect(wrapper.get('[role="alert"]').text()).toContain('not part of a type')
    wrapper.unmount()
  })

  it('shows what the engine said and what was already applied when a change fails half way', async () => {
    mocked.mockImplementation((_method?: string, url = ''): Promise<unknown> => {
      if (url.endsWith('/preview')) {
        return Promise.resolve(preview())
      }

      return Promise.reject(new ApiError(409, 'schema_change_failed', { message: 'Unknown data type', applied: [{ sql: 'x', operation: 'add_column', subject: 'first_ok', destructive: false }], failed: { sql: 'y', operation: 'add_column', subject: 'second', destructive: false } }))
    })
    const wrapper = mountEditor('mysql')
    await wrapper.findAll('input[aria-label="Type de la colonne"]')[2]?.setValue('varchar(80)')
    await settle()

    await wrapper.findAll('button').find((button) => button.text() === 'Valider les changements')?.trigger('click')
    await flushPromises()

    const alert = wrapper.findAll('[role="alert"]').map((node) => node.text()).join(' ')
    expect(alert).toContain('Unknown data type')
    expect(alert).toContain('Ajouter la colonne « first_ok »')
    expect(wrapper.findAll('button').some((button) => button.text() === 'Recharger la table')).toBe(true)
    wrapper.unmount()
  })

  it('previews, confirms and runs the drop of the table', async () => {
    const dropStatement = { sql: 'DROP TABLE "orders"', operation: 'drop_table', subject: 'orders', destructive: true }
    mocked.mockImplementation((_method?: string, url = ''): Promise<unknown> => Promise.resolve(url.endsWith('/preview') ? preview({ statements: [dropStatement], destructive: true }) : { statements: [dropStatement], transactional: true, destructive: true, table: null }))
    const wrapper = mountEditor()

    await wrapper.findAll('button').find((button) => button.text().startsWith('Supprimer la table'))?.trigger('click')
    await flushPromises()
    expect(structureCalls('preview')[0]?.[2]).toMatchObject({ drop: true })
    expect(wrapper.get('[role="dialog"]').text()).toContain('DROP TABLE "orders"')
    expect(structureCalls('apply')).toHaveLength(0)

    await wrapper.get('.confirm').trigger('click')
    await flushPromises()

    expect(structureCalls('apply')[0]?.[2]).toMatchObject({ drop: true, confirmed: true })
    expect(wrapper.emitted('applied')?.[0]).toEqual([null, 'orders'])
    wrapper.unmount()
  })

  it('highlights a renamed table', async () => {
    mocked.mockResolvedValue(preview())
    const wrapper = mountEditor()

    await typeInto(wrapper, 'input[id$="table-name"]', 'sales')
    await settle()

    expect(wrapper.get('input[id$="table-name"]').classes()).toContain('edited')
    expect(structureCalls('preview')[0]?.[2]).toMatchObject({ draft: { name: 'sales' } })
    wrapper.unmount()
  })

  it('turns the primary key on and off from the columns', async () => {
    mocked.mockResolvedValue(preview())
    const wrapper = mountEditor()
    const boxes = wrapper.findAll('input[aria-label="Dans la clé primaire"]')
    expect((boxes[0]?.element as HTMLInputElement).checked).toBe(true)

    await boxes[1]?.setValue(true)
    await settle()

    expect(structureCalls('preview')[0]?.[2]).toMatchObject({ draft: { indexes: expect.arrayContaining([expect.objectContaining({ primary: true, columns: ['id', 'status'] })]) } })
    wrapper.unmount()
  })

  it('lays the indexes out like phpMyAdmin: one row per column, with its rank, under the name, type and uniqueness of the index', async () => {
    const composite: TableDetail = { ...detail(), indexes: [{ name: 'orders_pkey', columns: ['id'], unique: true, primary: true }, { name: 'idx_two', columns: ['status', 'note'], unique: true, primary: false }] }
    const wrapper = mountEditor('postgresql', composite)

    expect(wrapper.findAll('.index-table thead th').map((th) => th.text()).filter((text) => text !== '' && text !== 'Actions')).toEqual(['Nom', 'Type', 'Unique', 'Colonnes'])
    const groups = wrapper.findAll('.index-table tbody')
    expect(groups.map((group) => group.attributes('data-kind'))).toEqual(['primary', 'index'])
    expect(groups[0]?.text()).toContain('clé primaire')
    expect(groups[0]?.text()).toContain('orders_pkey')
    const rows = groups[1]?.findAll('tr.col-row') ?? []
    expect(rows).toHaveLength(2)
    expect(rows.map((row) => row.get('.rank').text())).toEqual(['1', '2'])
    expect(rows.map((row) => selected(row.get('select')))).toEqual(['status', 'note'])
    expect((groups[1]?.get('input[aria-label="Unique"]').element as HTMLInputElement).checked).toBe(true)
    expect(groups[1]?.get('.type').text()).toContain('unique')
    wrapper.unmount()
  })

  it('lists the primary key first among the indexes, in step with the column boxes', async () => {
    mocked.mockResolvedValue(preview())
    const wrapper = mountEditor()
    const groups = wrapper.findAll('.index-table tbody')

    expect(groups[0]?.attributes('data-kind')).toBe('primary')
    expect(groups[0]?.attributes('data-status')).toBe('same')
    expect(groups[1]?.attributes('data-kind')).toBe('index')

    await pick(groups[0]!.get('select[aria-label^="Ajouter une colonne"]'), 'status')
    await settle()

    expect((wrapper.findAll('input[aria-label="Dans la clé primaire"]')[1]?.element as HTMLInputElement).checked).toBe(true)
    expect(wrapper.findAll('.index-table tbody')[0]?.attributes('data-status')).toBe('changed')
    expect(wrapper.findAll('.index-table tbody')[0]?.findAll('.rank').map((rank) => rank.text())).toEqual(['1', '2'])
    expect(structureCalls('preview')[0]?.[2]).toMatchObject({ draft: { indexes: expect.arrayContaining([expect.objectContaining({ primary: true, columns: ['id', 'status'] })]) } })
    wrapper.unmount()
  })

  it('removes and restores the primary key from its row in the index table', async () => {
    mocked.mockResolvedValue(preview())
    const wrapper = mountEditor()

    await wrapper.get('.index-table tbody[data-kind="primary"] button[aria-label="Retirer la clé primaire"]').trigger('click')
    await settle()
    expect(wrapper.get('.index-table tbody[data-kind="primary"]').attributes('data-status')).toBe('removed')
    expect((wrapper.findAll('input[aria-label="Dans la clé primaire"]')[0]?.element as HTMLInputElement).checked).toBe(false)

    await wrapper.get('.index-table tbody[data-kind="primary"]').findAll('button').find((button) => button.text() === 'Restaurer')?.trigger('click')
    await settle()
    expect(wrapper.get('.index-table tbody[data-kind="primary"]').attributes('data-status')).toBe('same')
    expect(wrapper.find('[aria-label="Modifications en attente"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('shows an empty primary key row on a table that has none, so one can be defined from the indexes too', async () => {
    const without: TableDetail = { ...detail(), indexes: [], foreignKeys: [] }
    const wrapper = mountEditor('postgresql', without)

    const group = wrapper.get('.index-table tbody[data-kind="primary"]')
    expect(group.text()).toContain('Aucune')
    await pick(group.get('select[aria-label^="Ajouter une colonne"]'), 'id')
    expect(wrapper.get('.index-table tbody[data-kind="primary"]').attributes('data-status')).toBe('new')
    wrapper.unmount()
  })

  it('edits an index: add a column, reorder them, make it unique, remove it', async () => {
    mocked.mockResolvedValue(preview())
    const wrapper = mountEditor()
    const group = (): ReturnType<typeof wrapper.get> => wrapper.get('.index-table tbody[data-kind="index"]')

    await pick(group().get('select[aria-label^="Ajouter une colonne"]'), 'note')
    await group().get('input[aria-label="Unique"]').setValue(true)
    await group().findAll('button').find((button) => button.attributes('aria-label')?.startsWith('Monter note'))?.trigger('click')
    await settle()

    expect(group().attributes('data-status')).toBe('changed')
    expect(group().findAll('tr.col-row select').map((select) => selected(select))).toEqual(['note', 'status'])
    expect(structureCalls('preview').at(-1)?.[2]).toMatchObject({ draft: { indexes: expect.arrayContaining([expect.objectContaining({ name: 'idx_status', columns: ['note', 'status'], unique: true })]) } })

    await group().findAll('button').find((button) => button.attributes('aria-label')?.startsWith('Ôter note'))?.trigger('click')
    expect(group().findAll('tr.col-row')).toHaveLength(1)

    await group().get('button[aria-label^="Retirer l’index"]').trigger('click')
    expect(group().attributes('data-status')).toBe('removed')
    wrapper.unmount()
  })

  it('does not offer a column already in the index, nor one that is removed from the table', async () => {
    const wrapper = mountEditor()
    await wrapper.findAll('button[aria-label^="Retirer la colonne"]')[2]?.trigger('click')

    const options = wrapper.get('.index-table tbody[data-kind="index"] select[aria-label^="Ajouter une colonne"]').findAll('option').map((option) => option.text())

    expect(options).not.toContain('status')
    expect(options).not.toContain('note')
    expect(options).toContain('id')
    wrapper.unmount()
  })

  it('edits a foreign key and lets one be added', async () => {
    mocked.mockResolvedValue(preview())
    const wrapper = mountEditor()

    await wrapper.get('.foreign-keys select[aria-label="À la suppression"]').setValue('restrict')
    await settle()
    expect(wrapper.get('.foreign-keys .card').attributes('data-status')).toBe('changed')

    await wrapper.findAll('button').find((button) => button.text().includes('Ajouter une clé étrangère'))?.trigger('click')
    expect(wrapper.findAll('.foreign-keys .card')).toHaveLength(2)
    expect(wrapper.findAll('.foreign-keys .card')[1]?.attributes('data-status')).toBe('new')
    wrapper.unmount()
  })

  it('offers no auto-increment, primary key or foreign key on ClickHouse, and keeps the sort key read-only', async () => {
    const clickhouse: TableDetail = {
      ...detail(),
      columns: [{ name: 'id', type: 'UInt64', nullable: false, default: null, autoIncrement: false, comment: null }],
      indexes: [{ name: 'ORDER BY', columns: ['id'], unique: false, primary: false }],
      foreignKeys: [],
    }
    const wrapper = mountEditor('clickhouse', clickhouse)

    expect(wrapper.find('input[aria-label="Auto-incrément"]').exists()).toBe(false)
    expect(wrapper.find('input[aria-label="Dans la clé primaire"]').exists()).toBe(false)
    expect(wrapper.find('.foreign-keys').exists()).toBe(false)
    expect(wrapper.get('.index-table tbody').attributes('data-kind')).toBe('locked')
    expect(wrapper.get('.index-table tbody').text()).toContain('lecture seule')
    expect(wrapper.find('.index-table tbody input').exists()).toBe(false)
    expect(wrapper.find('.index-table tbody[data-kind="primary"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('adds new columns as non-nullable on ClickHouse, where Nullable is the exception', async () => {
    const clickhouse: TableDetail = { ...detail(), columns: [{ name: 'id', type: 'UInt64', nullable: false, default: null, autoIncrement: false, comment: null }], indexes: [], foreignKeys: [] }
    const wrapper = mountEditor('clickhouse', clickhouse)

    await wrapper.findAll('button').find((button) => button.text().includes('Ajouter une colonne'))?.trigger('click')

    expect((wrapper.findAll('input[aria-label="Accepte NULL"]')[1]?.element as HTMLInputElement).checked).toBe(false)
    wrapper.unmount()

    const other = mountEditor('postgresql')
    await other.findAll('button').find((button) => button.text().includes('Ajouter une colonne'))?.trigger('click')
    expect((other.findAll('input[aria-label="Accepte NULL"]')[3]?.element as HTMLInputElement).checked).toBe(true)
    other.unmount()
  })

  it('starts again from the new table when the parent gives it back', async () => {
    mocked.mockResolvedValue(preview())
    const wrapper = mountEditor()
    await wrapper.findAll('input[aria-label="Type de la colonne"]')[2]?.setValue('varchar(80)')
    await settle()

    await wrapper.setProps({ table: { ...detail(), fingerprint: 'b'.repeat(64) } })
    await settle()

    expect(wrapper.find('[aria-label="Modifications en attente"]').exists()).toBe(false)
    wrapper.unmount()
  })
})
