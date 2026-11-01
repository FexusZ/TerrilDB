import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import { ApiError, api } from '../api/client'
import LoginView from './LoginView.vue'

vi.mock('../api/client', async (importOriginal) => ({ ...(await importOriginal<typeof import('../api/client')>()), api: vi.fn() }))

const mocked = vi.mocked(api)
const user = { id: '1', username: 'alice', email: 'a@exemple.test', totpEnabled: false, recoveryCodesLeft: 0, createdAt: '2026-11-01T10:00:00+00:00' }

async function mountView(path = '/login') {
  const pinia = createPinia()
  setActivePinia(pinia)
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/login', name: 'login', component: LoginView },
      { path: '/register', name: 'register', component: { template: '<p>register</p>' } },
      { path: '/connections', name: 'connections', component: { template: '<p>connections</p>' } },
      { path: '/connections/abc', name: 'workspace', component: { template: '<p>workspace</p>' } },
    ],
  })
  await router.push(path)
  await router.isReady()
  const wrapper = mount(LoginView, { global: { plugins: [pinia, router] } })

  return { wrapper, router }
}

async function submit(wrapper: Awaited<ReturnType<typeof mountView>>['wrapper'], login = 'alice', password = 'a long enough password') {
  await wrapper.get('#login').setValue(login)
  await wrapper.get('#password').setValue(password)
  await wrapper.get('form').trigger('submit')
  await flushPromises()
}

describe('LoginView', () => {
  beforeEach(() => mocked.mockReset())

  it('shows the login form and warns that a password cannot be reset', async () => {
    const { wrapper } = await mountView()

    expect(wrapper.get('label[for="login"]').text()).toContain('E-mail ou nom d’utilisateur')
    expect(wrapper.get('label[for="password"]').text()).toBe('Mot de passe')
    expect(wrapper.get('[role="note"]').text()).toContain('réinitialiser')
    expect(wrapper.get('input#password').attributes('type')).toBe('password')
  })

  it('logs in and goes to the connections', async () => {
    mocked.mockResolvedValue({ user, recoveryCodeUsed: false })
    const { wrapper, router } = await mountView()

    await submit(wrapper)

    expect(mocked).toHaveBeenCalledWith('POST', '/web/login', { login: 'alice', password: 'a long enough password' })
    expect(router.currentRoute.value.name).toBe('connections')
  })

  it('goes back to where the visitor wanted to go', async () => {
    mocked.mockResolvedValue({ user, recoveryCodeUsed: false })
    const { wrapper, router } = await mountView('/login?redirect=/connections/abc')

    await submit(wrapper)

    expect(router.currentRoute.value.fullPath).toBe('/connections/abc')
  })

  it('ignores a redirect that leaves the application', async () => {
    mocked.mockResolvedValue({ user, recoveryCodeUsed: false })
    const { wrapper, router } = await mountView('/login?redirect=//evil.example')

    await submit(wrapper)

    expect(router.currentRoute.value.name).toBe('connections')
  })

  it('says so when the credentials are wrong, and stays on the form', async () => {
    mocked.mockRejectedValueOnce(new ApiError(401, 'invalid_credentials'))
    const { wrapper, router } = await mountView()

    await submit(wrapper)

    expect(wrapper.get('[role="alert"]').text()).toBe('Identifiant ou mot de passe incorrect.')
    expect(router.currentRoute.value.name).toBe('login')
  })

  it('asks for the code when the account has a second factor, keeping the password', async () => {
    mocked.mockRejectedValueOnce(new ApiError(401, 'second_factor_required'))
    const { wrapper, router } = await mountView()
    await submit(wrapper)

    expect(wrapper.find('#password').exists()).toBe(false)
    expect(wrapper.get('label[for="code"]').text()).toContain('Code')
    expect(wrapper.find('[role="alert"]').exists()).toBe(false)

    mocked.mockResolvedValueOnce({ user, recoveryCodeUsed: false })
    await wrapper.get('#code').setValue('123456')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(mocked).toHaveBeenLastCalledWith('POST', '/web/login', { login: 'alice', password: 'a long enough password', code: '123456' })
    expect(router.currentRoute.value.name).toBe('connections')
  })

  it('reports a wrong code and lets the user try again', async () => {
    mocked.mockRejectedValueOnce(new ApiError(401, 'second_factor_required'))
    const { wrapper } = await mountView()
    await submit(wrapper)

    mocked.mockRejectedValueOnce(new ApiError(401, 'invalid_second_factor'))
    await wrapper.get('#code').setValue('000000')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(wrapper.get('[role="alert"]').text()).toContain('Code')
    expect(wrapper.find('#code').exists()).toBe(true)
  })

  it('accepts a recovery code in place of the app code', async () => {
    mocked.mockRejectedValueOnce(new ApiError(401, 'second_factor_required'))
    const { wrapper } = await mountView()
    await submit(wrapper)

    await wrapper.get('button.switch').trigger('click')

    expect(wrapper.get('label[for="code"]').text()).toContain('récupération')
  })

  it('can go back from the code step to the credentials', async () => {
    mocked.mockRejectedValueOnce(new ApiError(401, 'second_factor_required'))
    const { wrapper } = await mountView()
    await submit(wrapper)

    await wrapper.get('button.back').trigger('click')

    expect(wrapper.find('#password').exists()).toBe(true)
    expect((wrapper.get('#password').element as HTMLInputElement).value).toBe('')
  })

  it('tells how long to wait when throttled', async () => {
    mocked.mockRejectedValueOnce(new ApiError(429, 'too_many_attempts', { retryAfter: 42 }))
    const { wrapper } = await mountView()

    await submit(wrapper)

    expect(wrapper.get('[role="alert"]').text()).toContain('42')
  })

  it('disables the button while the request is running', async () => {
    let finish: (value: unknown) => void = () => undefined
    mocked.mockReturnValue(new Promise((resolve) => { finish = resolve }))
    const { wrapper } = await mountView()
    await wrapper.get('#login').setValue('alice')
    await wrapper.get('#password').setValue('a long enough password')

    await wrapper.get('form').trigger('submit')

    expect(wrapper.get('button[type="submit"]').attributes('disabled')).toBeDefined()
    finish({ user, recoveryCodeUsed: false })
    await flushPromises()
  })
})
