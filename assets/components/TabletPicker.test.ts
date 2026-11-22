import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it } from 'vitest'
import { useWorkspaceStore } from '../stores/workspace'
import TabletPicker from './TabletPicker.vue'

const tablets = [
  { alias: 'test-0000000001', cell: 'test', keyspace: 'shop', shard: '0', type: 'PRIMARY', serving: true },
  { alias: 'test-0000000002', cell: 'test', keyspace: 'shop', shard: '0', type: 'REPLICA', serving: true },
  { alias: 'test-0000000003', cell: 'test', keyspace: 'shop', shard: '0', type: 'RDONLY', serving: false },
]

describe('TabletPicker', () => {
  beforeEach(() => setActivePinia(createPinia()))

  it('says how many tablets are left out and why, instead of hiding them', () => {
    const store = useWorkspaceStore()
    store.tablets = tablets
    store.skippedTablets = [
      { reason: 'type_not_targetable', type: 'BACKUP' },
      { reason: 'type_not_targetable', type: 'BACKUP' },
      { reason: 'unexpected_name', type: 'REPLICA' },
      { reason: 'type_not_targetable', type: null },
    ]

    const note = mount(TabletPicker).get('.skipped').text()

    expect(note).toContain('4 tablets ne sont pas proposés')
    expect(note).toContain('2 en sauvegarde')
    expect(note).toContain('1 au nom inhabituel')
    expect(note).toContain('1 de type inconnu')
  })

  it('stays silent when every tablet can be chosen', () => {
    const store = useWorkspaceStore()
    store.tablets = tablets

    expect(mount(TabletPicker).find('.skipped').exists()).toBe(false)
  })

  it('explains why a tablet must be chosen and lists them with their role', () => {
    const store = useWorkspaceStore()
    store.tablets = tablets

    const wrapper = mount(TabletPicker)

    expect(wrapper.text()).toContain('un seul tablet')
    const options = wrapper.findAll('option').map((option) => option.text())
    expect(options[0]).toContain('Choisir un tablet')
    expect(options.join('|')).toContain('test-0000000002')
    expect(options.join('|')).toContain('réplica')
    expect(options.join('|')).toContain('hors service')
  })

  it('picks the tablet in the store', async () => {
    const store = useWorkspaceStore()
    store.tablets = tablets
    const wrapper = mount(TabletPicker)

    await wrapper.find('select').setValue('test-0000000002')

    expect(store.tablet).toBe('test-0000000002')
    expect(wrapper.text()).toContain('Vous lisez le tablet test-0000000002')
  })
})
