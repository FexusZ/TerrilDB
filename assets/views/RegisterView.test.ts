import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import { ApiError, api } from '../api/client'
import RegisterView from './RegisterView.vue'

vi.mock('../api/client', async (importOriginal) => ({ ...(await importOriginal<typeof import('../api/client')>()), api: vi.fn() }))

const mocked = vi.mocked(api)
const user = { id: '1', username: 'marie', email: 'm@exemple.test', role: 'member', recoveryPhrase: false, totpEnabled: false, recoveryCodesLeft: 0, createdAt: '2026-11-01T10:00:00+00:00' }

async function mountView(path = '/register') {
  const pinia = createPinia()
  setActivePinia(pinia)
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/register', name: 'register', component: RegisterView },
      { path: '/login', name: 'login', component: { template: '<p />' } },
      { path: '/connections', name: 'connections', component: { template: '<p />' } },
      { path: '/workspace', name: 'workspace-home', component: { template: '<p />' } },
    ],
  })
  await router.push(path)
  await router.isReady()

  return { wrapper: mount(RegisterView, { global: { plugins: [pinia, router] } }), router }
}

async function fill(wrapper: Awaited<ReturnType<typeof mountView>>['wrapper']) {
  await wrapper.get('#email').setValue('m@exemple.test')
  await wrapper.get('#username').setValue('marie')
  await wrapper.get('#password').setValue('a long enough password')
  await wrapper.get('#confirmation').setValue('a long enough password')
  await wrapper.get('form').trigger('submit')
  await flushPromises()
}

describe('RegisterView', () => {
  beforeEach(() => mocked.mockReset())

  it('sends the invitation found in the link', async () => {
    mocked.mockResolvedValueOnce({}).mockResolvedValueOnce({ user, recoveryCodeUsed: false })
    const { wrapper, router } = await mountView('/register?invitation=inv_abc')

    expect(wrapper.text()).toContain('invitation')
    await fill(wrapper)

    expect(mocked).toHaveBeenNthCalledWith(1, 'POST', '/web/register', { email: 'm@exemple.test', username: 'marie', password: 'a long enough password', invitation: 'inv_abc' })
    expect(router.currentRoute.value.name).toBe('workspace-home')
  })

  it('registers without invitation when the link has none', async () => {
    mocked.mockResolvedValueOnce({}).mockResolvedValueOnce({ user, recoveryCodeUsed: false })
    const { wrapper } = await mountView()

    await fill(wrapper)

    expect(mocked).toHaveBeenNthCalledWith(1, 'POST', '/web/register', { email: 'm@exemple.test', username: 'marie', password: 'a long enough password' })
  })

  it('says so when the invitation is refused', async () => {
    mocked.mockRejectedValueOnce(new ApiError(403, 'invalid_invitation'))
    const { wrapper } = await mountView('/register?invitation=inv_abc')

    await fill(wrapper)

    expect(wrapper.get('[role="alert"]').text()).toContain('invitation')
  })
})
