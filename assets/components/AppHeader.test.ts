import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import { useSessionStore } from '../stores/session'
import { useThemeStore } from '../stores/theme'
import AppHeader from './AppHeader.vue'

beforeEach(() => {
  vi.stubGlobal('matchMedia', () => ({ matches: false, addEventListener: vi.fn() }))
  localStorage.clear()
})

function mountHeader() {
  const pinia = createPinia()
  setActivePinia(pinia)
  useSessionStore().user = { id: '1', username: 'alice', email: 'a@exemple.test', role: 'admin', recoveryPhrase: false, totpEnabled: false, recoveryCodesLeft: 0, createdAt: '2026-11-01T10:00:00+00:00' }
  const router = createRouter({ history: createMemoryHistory(), routes: ['connections', 'security', 'login'].map((name) => ({ path: `/${name}`, name, component: { template: '<p />' } })) })
  useThemeStore().init()

  return mount(AppHeader, { global: { plugins: [pinia, router] } })
}

describe('AppHeader theme switch', () => {
  it('names the current choice for assistive technology', () => {
    const wrapper = mountHeader()

    expect(wrapper.get('button.theme').attributes('aria-label')).toBe('Thème : automatique (suit le système). Changer.')
  })

  it('cycles through automatic, light and dark', async () => {
    const wrapper = mountHeader()

    await wrapper.get('button.theme').trigger('click')
    expect(wrapper.get('button.theme').attributes('aria-label')).toContain('clair')
    expect(document.documentElement.dataset.theme).toBe('light')

    await wrapper.get('button.theme').trigger('click')
    expect(wrapper.get('button.theme').attributes('aria-label')).toContain('sombre')
    expect(document.documentElement.dataset.theme).toBe('dark')
  })
})
