import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory } from 'vue-router'
import type { User } from './api/types'
import { createAppRouter } from './router'
import { useSessionStore } from './stores/session'

vi.mock('./api/client', async (importOriginal) => ({ ...(await importOriginal<typeof import('./api/client')>()), api: vi.fn() }))

const alice: User = { id: '1', username: 'alice', email: 'a@exemple.test', totpEnabled: false, recoveryCodesLeft: 0, role: 'admin', recoveryPhrase: false, createdAt: '2026-11-01T10:00:00+00:00' }

const id = '3f2b8c1e-5d4a-4e7b-9c6d-1a2b3c4d5e6f'

function routerFor(user: User | null) {
  setActivePinia(createPinia())
  const session = useSessionStore()
  session.user = user
  session.loaded = true

  return createAppRouter(createMemoryHistory())
}

describe('router guard', () => {
  beforeEach(() => vi.clearAllMocks())

  it('sends an anonymous visitor to the login, remembering where they wanted to go', async () => {
    const router = routerFor(null)

    await router.push(`/connections/${id}`)

    expect(router.currentRoute.value.name).toBe('login')
    expect(router.currentRoute.value.query.redirect).toBe(`/connections/${id}`)
  })

  it('lets an anonymous visitor reach the public pages', async () => {
    const router = routerFor(null)

    await router.push('/register')

    expect(router.currentRoute.value.name).toBe('register')
  })

  it('keeps a logged-in user away from the login page', async () => {
    const router = routerFor(alice)

    await router.push('/login')

    expect(router.currentRoute.value.name).toBe('connections')
  })

  it('opens the connections page at the root: it is the home page', async () => {
    const router = routerFor(alice)

    await router.push('/')

    expect(router.currentRoute.value.name).toBe('connections')
    expect(router.currentRoute.value.path).toBe('/connections')
  })

  it('keeps the connections list as the place to manage connections', async () => {
    const router = routerFor(alice)

    await router.push('/connections')

    expect(router.currentRoute.value.name).toBe('connections')
  })

  it('lets a visitor reach the recovery page', async () => {
    const router = routerFor(null)

    await router.push('/recover')

    expect(router.currentRoute.value.name).toBe('recover')
  })

  it('knows the workspace routes', async () => {
    const router = routerFor(alice)

    await router.push(`/connections/${id}`)
    expect(router.currentRoute.value.name).toBe('workspace')
    await router.push(`/connections/${id}/tables/orders`)
    expect(router.currentRoute.value.name).toBe('table')
    expect(router.currentRoute.value.params.name).toBe('orders')
    await router.push(`/connections/${id}/audit`)
    expect(router.currentRoute.value.name).toBe('audit')
    await router.push(`/connections/${id}/diagram`)
    expect(router.currentRoute.value.name).toBe('diagram')
    await router.push('/security/accounts')
    expect(router.currentRoute.value.name).toBe('accounts')
    await router.push('/security/invitations')
    expect(router.currentRoute.value.name).toBe('invitations')
    await router.push(`/connections/${id}/edit`)
    expect(router.currentRoute.value.name).toBe('connection-edit')
    await router.push('/connections/new')
    expect(router.currentRoute.value.name).toBe('connection-new')
    await router.push('/security')
    expect(router.currentRoute.value.name).toBe('security')
    await router.push('/security/activity')
    expect(router.currentRoute.value.name).toBe('activity')
    await router.push('/security/tokens')
    expect(router.currentRoute.value.name).toBe('tokens')
  })

  it('refuses a connection id that is not a UUID', async () => {
    const router = routerFor(alice)

    await router.push('/connections/abc')
    expect(router.currentRoute.value.name).toBe('connections')
    await router.push('/connections/abc/edit')
    expect(router.currentRoute.value.name).toBe('connections')
    await router.push('/connections/..%2F..%2Fx/tables/orders')
    expect(router.currentRoute.value.name).toBe('connections')
  })

  it('answers an unknown address with the workspace', async () => {
    const router = routerFor(alice)

    await router.push('/nothing/here')

    expect(router.currentRoute.value.name).toBe('connections')
  })
})
