import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import LintList from './LintList.vue'

describe('LintList', () => {
  it('shows the French rule name, the columns and the advice of each finding', () => {
    const wrapper = mount(LintList, {
      props: {
        result: {
          findings: [
            { rule: 'type_mismatch', severity: 'warning', message: 'La colonne texte « code » est comparée à un nombre.', suggestion: 'Écrire la valeur comme un texte.', columns: ['code'] },
            { rule: 'distinct_join', severity: 'info', message: 'SELECT DISTINCT après une jointure.', suggestion: null, columns: [] },
          ],
        },
      },
    })

    const items = wrapper.findAll('li.finding')
    expect(items).toHaveLength(2)
    expect(items[0]!.get('.label').text()).toBe('Types comparés différents')
    expect(items[0]!.get('.column').text()).toBe('code')
    expect(items[0]!.classes()).toContain('warning')
    expect(items[0]!.text()).toContain('Écrire la valeur comme un texte.')
    expect(items[1]!.get('.label').text()).toBe('DISTINCT après jointure')
    expect(items[1]!.findAll('.column')).toHaveLength(0)
    expect(items[1]!.find('.suggestion').exists()).toBe(false)
  })

  it('says so when nothing is found', () => {
    const wrapper = mount(LintList, { props: { result: { findings: [] } } })

    expect(wrapper.text()).toContain('Aucun motif connu')
  })
})
