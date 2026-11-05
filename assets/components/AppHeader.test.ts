import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import { useSessionStore } from '../stores/session'
import { useThemeStore } from '../stores/theme'
import { useTreeStore } from '../stores/tree'
import AppHeader from './AppHeader.vue'

beforeEach(() => {
  vi.stubGlobal('matchMedia', () => ({ matches: false, addEventListener: vi.fn() }))
  localStorage.clear()
})

function mountHeader() {
  const pinia = createPinia()
  setActivePinia(pinia)
  useSessionStore().user = { id: '1', username: 'alice', email: 'a@exemple.test', role: 'admin', recoveryPhrase: false, totpEnabled: false, recoveryCodesLeft: 0, createdAt: '2026-11-01T10:00:00+00:00' }
  const router = createRouter({ history: createMemoryHistory(), routes: ['connections', 'security', 'login', 'workspace-home'].map((name) => ({ path: `/${name}`, name, component: { template: '<p />' } })) })
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

describe('AppHeader navigation', () => {
  it('brings the logo back to the workspace', () => {
    const wrapper = mountHeader()

    expect(wrapper.get('a.home').attributes('href')).toBe('/workspace-home')
  })

  it('forgets the tree of connections when the session ends', async () => {
    const wrapper = mountHeader()
    const reset = vi.spyOn(useTreeStore(), 'reset')

    await wrapper.findAll('button').find((button) => button.text() === 'Déconnexion')?.trigger('click')
    await flushPromises()

    expect(reset).toHaveBeenCalled()
  })
})
