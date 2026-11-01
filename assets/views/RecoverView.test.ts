import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import { ApiError, api } from '../api/client'
import RecoverView from './RecoverView.vue'

vi.mock('../api/client', async (importOriginal) => ({ ...(await importOriginal<typeof import('../api/client')>()), api: vi.fn() }))

const mocked = vi.mocked(api)

async function mountView() {
  const pinia = createPinia()
  setActivePinia(pinia)
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/recover', name: 'recover', component: RecoverView },
      { path: '/login', name: 'login', component: { template: '<p />' } },
    ],
  })
  await router.push('/recover')
  await router.isReady()

  return { wrapper: mount(RecoverView, { global: { plugins: [pinia, router] } }), router }
}

async function fill(wrapper: Awaited<ReturnType<typeof mountView>>['wrapper'], password = 'a brand new passphrase', confirmation = password) {
  await wrapper.get('#login').setValue('alice')
  await wrapper.get('#phrase').setValue('abcd efgh jkmn pqrs tvwx yz01 2345 6789')
  await wrapper.get('#password').setValue(password)
  await wrapper.get('#confirmation').setValue(confirmation)
  await wrapper.get('form').trigger('submit')
  await flushPromises()
}

describe('RecoverView', () => {
  beforeEach(() => mocked.mockReset())

  it('explains what the phrase can and cannot do', async () => {
    const { wrapper } = await mountView()

    expect(wrapper.text()).toContain('phrase de récupération')
    expect(wrapper.get('[role="note"]').text()).toContain('double authentification')
  })

  it('sends the phrase and the new password, then goes to the login with a notice', async () => {
    mocked.mockResolvedValueOnce(undefined)
    const { wrapper, router } = await mountView()

    await fill(wrapper)

    expect(mocked).toHaveBeenCalledWith('POST', '/web/recover', { login: 'alice', phrase: 'abcd efgh jkmn pqrs tvwx yz01 2345 6789', newPassword: 'a brand new passphrase' })
    expect(router.currentRoute.value.name).toBe('login')
    expect(router.currentRoute.value.query.recovered).toBe('1')
  })

  it('refuses two different passwords without calling the server', async () => {
    const { wrapper } = await mountView()

    await fill(wrapper, 'a brand new passphrase', 'something else entirely')

    expect(mocked).not.toHaveBeenCalled()
    expect(wrapper.get('[role="alert"]').text()).toContain('pas identiques')
  })

  it('shows the generic refusal and stays on the page', async () => {
    mocked.mockRejectedValueOnce(new ApiError(403, 'invalid_recovery'))
    const { wrapper, router } = await mountView()

    await fill(wrapper)

    expect(wrapper.get('[role="alert"]').text()).toContain('phrase')
    expect(router.currentRoute.value.name).toBe('recover')
  })
})
