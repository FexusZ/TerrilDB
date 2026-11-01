import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import { ApiError, api } from '../api/client'
import type { User } from '../api/types'
import { useSessionStore } from '../stores/session'
import SecurityView from './SecurityView.vue'

vi.mock('../api/client', async (importOriginal) => ({ ...(await importOriginal<typeof import('../api/client')>()), api: vi.fn() }))

const mocked = vi.mocked(api)
const alice: User = { id: '1', username: 'alice', email: 'alice@exemple.test', totpEnabled: false, recoveryCodesLeft: 0, createdAt: '2026-11-01T10:00:00+00:00' }
const codes = Array.from({ length: 10 }, (_, i) => `abcd-efgh-ijkl-mn0${String(i)}`)

async function mountView(user: User = alice) {
  const pinia = createPinia()
  setActivePinia(pinia)
  useSessionStore().user = user
  const router = createRouter({
    history: createMemoryHistory(),
    routes: ['connections', 'security', 'login', 'tokens'].map((name) => ({ path: `/${name}`, name, component: { template: '<p />' } })),
  })
  await router.push('/security')
  const wrapper = mount(SecurityView, { global: { plugins: [pinia, router] } })
  await flushPromises()

  return wrapper
}

describe('SecurityView', () => {
  beforeEach(() => mocked.mockReset())

  it('shows whose account it is', async () => {
    const wrapper = await mountView()

    expect(wrapper.text()).toContain('alice@exemple.test')
  })

  it('leads to the API tokens', async () => {
    const wrapper = await mountView()

    expect(wrapper.get('section.api a.more').attributes('href')).toBe('/tokens')
  })

  describe('password', () => {
    async function fill(wrapper: Awaited<ReturnType<typeof mountView>>, current: string, next: string, confirmation: string) {
      await wrapper.get('#current').setValue(current)
      await wrapper.get('#new').setValue(next)
      await wrapper.get('#confirmation').setValue(confirmation)
      await wrapper.get('form.password').trigger('submit')
      await flushPromises()
    }

    it('warns that the connections and the second factor are re-encrypted', async () => {
      const wrapper = await mountView()

      expect(wrapper.get('form.password [role="note"]').text()).toContain('rechiffrées')
    })

    it('changes the password and clears the fields', async () => {
      const wrapper = await mountView()
      mocked.mockResolvedValueOnce({ user: alice })

      await fill(wrapper, 'old password here', 'a brand new passphrase', 'a brand new passphrase')

      expect(mocked).toHaveBeenCalledWith('POST', '/web/account/password', { current: 'old password here', new: 'a brand new passphrase' })
      expect(wrapper.get('form.password [role="status"]').text()).toContain('modifié')
      expect((wrapper.get('#current').element as HTMLInputElement).value).toBe('')
    })

    it('refuses a confirmation that differs, without calling the server', async () => {
      const wrapper = await mountView()

      await fill(wrapper, 'old password here', 'a brand new passphrase', 'something else entirely')

      expect(wrapper.get('form.password [role="alert"]').text()).toContain('identiques')
      expect(mocked).not.toHaveBeenCalled()
    })

    it('shows why the server refused', async () => {
      const wrapper = await mountView()
      mocked.mockRejectedValueOnce(new ApiError(401, 'invalid_credentials'))

      await fill(wrapper, 'wrong password!!', 'a brand new passphrase', 'a brand new passphrase')

      expect(wrapper.get('form.password [role="alert"]').text()).toContain('incorrect')
    })
  })

  describe('second factor', () => {
    it('offers to enable it', async () => {
      const wrapper = await mountView()

      expect(wrapper.get('section.totp').text()).toContain('Désactivée')
      expect(wrapper.find('button.enable').exists()).toBe(true)
    })

    it('shows the QR code and the secret, then confirms with a code and reveals the recovery codes once', async () => {
      const wrapper = await mountView()
      mocked.mockResolvedValueOnce({ secret: 'ABCDEFGHIJKLMNOP', uri: 'otpauth://totp/TerrilDB:alice?secret=ABCDEFGHIJKLMNOP&issuer=TerrilDB' })

      await wrapper.get('button.enable').trigger('click')
      await flushPromises()

      expect(wrapper.find('svg.qr').exists()).toBe(true)
      expect(wrapper.get('.secret').text()).toContain('ABCDEFGHIJKLMNOP')

      mocked.mockResolvedValueOnce({ recoveryCodes: codes })
      mocked.mockResolvedValueOnce({ user: { ...alice, totpEnabled: true, recoveryCodesLeft: 10 } })
      await wrapper.get('#totp-code').setValue('123456')
      await wrapper.get('form.totp-confirm').trigger('submit')
      await flushPromises()

      expect(mocked).toHaveBeenCalledWith('POST', '/web/account/totp/confirm', { secret: 'ABCDEFGHIJKLMNOP', code: '123456' })
      expect(wrapper.findAll('.codes li')).toHaveLength(10)
      expect(wrapper.get('.codes-notice').text()).toContain('ne seront plus affichés')

      await wrapper.get('button.noted').trigger('click')
      expect(wrapper.find('.codes').exists()).toBe(false)
      expect(wrapper.get('section.totp').text()).toContain('Activée')
    })

    it('says when the code is wrong and lets the user retry', async () => {
      const wrapper = await mountView()
      mocked.mockResolvedValueOnce({ secret: 'ABCDEFGHIJKLMNOP', uri: 'otpauth://totp/x?secret=ABCDEFGHIJKLMNOP' })
      await wrapper.get('button.enable').trigger('click')
      await flushPromises()

      mocked.mockRejectedValueOnce(new ApiError(401, 'invalid_second_factor'))
      await wrapper.get('#totp-code').setValue('000000')
      await wrapper.get('form.totp-confirm').trigger('submit')
      await flushPromises()

      expect(wrapper.get('form.totp-confirm [role="alert"]').text()).toContain('Code')
      expect(wrapper.find('#totp-code').exists()).toBe(true)
    })

    it('can be disabled with the password', async () => {
      const wrapper = await mountView({ ...alice, totpEnabled: true, recoveryCodesLeft: 7 })
      expect(wrapper.get('section.totp').text()).toContain('Activée')
      mocked.mockResolvedValueOnce(undefined)

      await wrapper.get('#disable-password').setValue('the password!!')
      await wrapper.get('form.totp-disable').trigger('submit')
      await flushPromises()

      expect(mocked).toHaveBeenCalledWith('POST', '/web/account/totp/disable', { password: 'the password!!' })
      expect(wrapper.get('section.totp').text()).toContain('Désactivée')
    })

    it('reports a wrong password when disabling', async () => {
      const wrapper = await mountView({ ...alice, totpEnabled: true, recoveryCodesLeft: 7 })
      mocked.mockRejectedValueOnce(new ApiError(401, 'invalid_credentials'))

      await wrapper.get('#disable-password').setValue('wrong password!!')
      await wrapper.get('form.totp-disable').trigger('submit')
      await flushPromises()

      expect(wrapper.get('form.totp-disable [role="alert"]').text()).toContain('incorrect')
    })
  })

  describe('recovery codes', () => {
    it('shows how many are left', async () => {
      const wrapper = await mountView({ ...alice, totpEnabled: true, recoveryCodesLeft: 7 })

      expect(wrapper.get('section.recovery').text()).toContain('7 sur 10')
    })

    it('is not offered without a second factor', async () => {
      const wrapper = await mountView()

      expect(wrapper.find('section.recovery').exists()).toBe(false)
    })

    it('generates new codes with the password and shows them once', async () => {
      const wrapper = await mountView({ ...alice, totpEnabled: true, recoveryCodesLeft: 3 })
      mocked.mockResolvedValueOnce({ recoveryCodes: codes })

      await wrapper.get('#recovery-password').setValue('the password!!')
      await wrapper.get('form.recovery-form').trigger('submit')
      await flushPromises()

      expect(mocked).toHaveBeenCalledWith('POST', '/web/account/recovery-codes', { password: 'the password!!' })
      expect(wrapper.findAll('.codes li')).toHaveLength(10)
      expect(wrapper.get('section.recovery').text()).toContain('10 sur 10')
    })
  })
})
