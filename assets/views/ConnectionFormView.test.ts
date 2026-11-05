import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import { ApiError, api } from '../api/client'
import { useSessionStore } from '../stores/session'
import ConnectionFormView from './ConnectionFormView.vue'

vi.mock('../api/client', async (importOriginal) => ({ ...(await importOriginal<typeof import('../api/client')>()), api: vi.fn() }))

const mocked = vi.mocked(api)
const summary = { id: 'abc', name: 'Production', engine: 'postgresql', createdAt: '2026-10-01T10:00:00+00:00', updatedAt: '2026-10-31T10:00:00+00:00' }

async function mountForm(id?: string) {
  const pinia = createPinia()
  setActivePinia(pinia)
  useSessionStore().user = { id: '1', username: 'alice', email: 'a@exemple.test', totpEnabled: false, recoveryCodesLeft: 0, role: 'admin', recoveryPhrase: false, createdAt: '2026-11-01T10:00:00+00:00' }
  const router = createRouter({
    history: createMemoryHistory(),
    routes: ['connections', 'connection-new', 'connection-edit', 'workspace', 'security', 'login', 'workspace-home'].map((name) => ({
      path: name === 'connection-edit' ? '/connections/:id/edit' : name === 'workspace' ? '/connections/:id' : `/${name}`,
      name,
      component: { template: '<p />' },
    })),
  })
  await router.push('/connections')
  const wrapper = mount(ConnectionFormView, { props: { id }, global: { plugins: [pinia, router] } })
  await flushPromises()

  return { wrapper, router }
}

async function fill(wrapper: Awaited<ReturnType<typeof mountForm>>['wrapper'], values: Record<string, string>) {
  for (const [id, value] of Object.entries(values)) {
    await wrapper.get(`#${id}`).setValue(value)
  }
}

describe('ConnectionFormView', () => {
  beforeEach(() => mocked.mockReset())

  it('starts as an empty form for a new connection, PostgreSQL selected', async () => {
    const { wrapper } = await mountForm()

    expect(wrapper.get('h1').text()).toBe('Nouvelle connexion')
    expect((wrapper.get('input[value="postgresql"]').element as HTMLInputElement).checked).toBe(true)
    expect(wrapper.get('#port').attributes('placeholder')).toBe('5432')
    expect(wrapper.get('[role="note"]').text()).toContain('chiffrés')
  })

  it('suggests the port of the chosen engine', async () => {
    const { wrapper } = await mountForm()

    await wrapper.get('input[value="mysql"]').setValue(true)

    expect(wrapper.get('#port').attributes('placeholder')).toBe('3306')
  })

  it('offers ClickHouse, whose HTTP port is 8123', async () => {
    const { wrapper } = await mountForm()

    await wrapper.get('input[value="clickhouse"]').setValue(true)

    expect(wrapper.get('#port').attributes('placeholder')).toBe('8123')
  })

  it('offers Redis, which asks for a database number and an ACL user', async () => {
    const { wrapper } = await mountForm()

    await wrapper.get('input[value="redis"]').setValue(true)

    expect(wrapper.get('#port').attributes('placeholder')).toBe('6379')
    expect(wrapper.get('label[for="database"]').text()).toContain('Numéro de base')
    expect(wrapper.get('#database').attributes('placeholder')).toBe('0')
    expect(wrapper.get('#username').attributes('placeholder')).toBe('default')
  })

  it('keeps the usual labels for the SQL engines', async () => {
    const { wrapper } = await mountForm()

    expect(wrapper.get('label[for="database"]').text()).toContain('Base de données')
  })

  it('saves a new connection and goes back to the list', async () => {
    const { wrapper, router } = await mountForm()
    await fill(wrapper, { name: 'Production', host: 'db.exemple.test', port: '5433', database: 'shop', username: 'lecteur', password: 'secret-value' })
    mocked.mockResolvedValueOnce({ connection: summary })

    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(mocked).toHaveBeenCalledWith('POST', '/web/connections', {
      name: 'Production', engine: 'postgresql', host: 'db.exemple.test', port: 5433, database: 'shop', username: 'lecteur', password: 'secret-value',
    })
    expect(router.currentRoute.value.name).toBe('connections')
  })

  it('leaves the port to the server when it is empty', async () => {
    const { wrapper } = await mountForm()
    await fill(wrapper, { name: 'P', host: 'h', database: 'd', username: 'u', password: 'p' })
    mocked.mockResolvedValueOnce({ connection: summary })

    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(mocked.mock.calls[0]?.[2]).not.toHaveProperty('port')
  })

  it('refuses a port that is not a number, without calling the server', async () => {
    const { wrapper } = await mountForm()
    await fill(wrapper, { name: 'P', host: 'h', port: '70000', database: 'd', username: 'u', password: 'p' })

    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(wrapper.get('[role="alert"]').text()).toContain('port')
    expect(mocked).not.toHaveBeenCalled()
  })

  it('shows the server refusal, such as a name already used', async () => {
    const { wrapper, router } = await mountForm()
    await fill(wrapper, { name: 'Production', host: 'h', database: 'd', username: 'u', password: 'p' })
    mocked.mockRejectedValueOnce(new ApiError(409, 'connection_name_used'))

    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(wrapper.get('[role="alert"]').text()).toContain('nom')
    expect(router.currentRoute.value.name).toBe('connections')
  })

  it('tests the parameters without saving, and reports the time it took', async () => {
    const { wrapper } = await mountForm()
    await fill(wrapper, { name: 'P', host: 'h', database: 'd', username: 'u', password: 'p' })
    mocked.mockResolvedValueOnce({ ok: true, durationMs: 23 })

    await wrapper.get('button.test').trigger('click')
    await flushPromises()

    expect(mocked).toHaveBeenCalledWith('POST', '/web/connections/test', expect.objectContaining({ host: 'h', password: 'p' }))
    expect(wrapper.get('[role="status"]').text()).toContain('23 ms')
  })

  it('reports a failed test as such', async () => {
    const { wrapper } = await mountForm()
    await fill(wrapper, { name: 'P', host: 'h', database: 'd', username: 'u', password: 'p' })
    mocked.mockRejectedValueOnce(new ApiError(502, 'target_unreachable'))

    await wrapper.get('button.test').trigger('click')
    await flushPromises()

    expect(wrapper.get('[role="alert"]').text()).toContain('joindre')
    expect(wrapper.find('[role="status"]').exists()).toBe(false)
  })

  it('loads a saved connection for editing, with the password left blank', async () => {
    mocked.mockResolvedValueOnce({ connection: summary, parameters: { host: 'db.exemple.test', port: 5432, database: 'shop', username: 'lecteur', hasPassword: true } })

    const { wrapper } = await mountForm('abc')

    expect(wrapper.get('h1').text()).toBe('Modifier la connexion')
    expect((wrapper.get('#name').element as HTMLInputElement).value).toBe('Production')
    expect((wrapper.get('#host').element as HTMLInputElement).value).toBe('db.exemple.test')
    expect((wrapper.get('#port').element as HTMLInputElement).value).toBe('5432')
    expect((wrapper.get('#password').element as HTMLInputElement).value).toBe('')
    expect(wrapper.get('#password').attributes('placeholder')).toContain('conserver')
  })

  it('keeps the saved password when it is left blank on update', async () => {
    mocked.mockResolvedValueOnce({ connection: summary, parameters: { host: 'h', port: 5432, database: 'd', username: 'u', hasPassword: true } })
    const { wrapper } = await mountForm('abc')
    await wrapper.get('#name').setValue('Renommée')
    mocked.mockResolvedValueOnce({ connection: { ...summary, name: 'Renommée' } })

    await wrapper.get('form').trigger('submit')
    await flushPromises()

    const [method, path, body] = mocked.mock.calls[1] as [string, string, Record<string, unknown>]
    expect(method).toBe('PUT')
    expect(path).toBe('/web/connections/abc')
    expect(body).not.toHaveProperty('password')
    expect(body.name).toBe('Renommée')
  })

  it('tests the saved connection when nothing was changed', async () => {
    mocked.mockResolvedValueOnce({ connection: summary, parameters: { host: 'h', port: 5432, database: 'd', username: 'u', hasPassword: true } })
    const { wrapper } = await mountForm('abc')
    mocked.mockResolvedValueOnce({ ok: true, durationMs: 9 })

    await wrapper.get('button.test').trigger('click')
    await flushPromises()

    expect(mocked).toHaveBeenLastCalledWith('POST', '/web/connections/abc/test')
    expect(wrapper.get('[role="status"]').text()).toContain('9 ms')
  })

  it('asks for the password to test changed parameters', async () => {
    mocked.mockResolvedValueOnce({ connection: summary, parameters: { host: 'h', port: 5432, database: 'd', username: 'u', hasPassword: true } })
    const { wrapper } = await mountForm('abc')
    await wrapper.get('#host').setValue('other.exemple.test')

    await wrapper.get('button.test').trigger('click')
    await flushPromises()

    expect(wrapper.get('[role="alert"]').text()).toContain('mot de passe')
    expect(mocked).toHaveBeenCalledTimes(1)
  })

  it('says so when the connection to edit does not exist', async () => {
    mocked.mockRejectedValueOnce(new ApiError(404, 'not_found'))

    const { wrapper } = await mountForm('missing')

    expect(wrapper.get('[role="alert"]').text()).toContain('introuvable')
  })
})
