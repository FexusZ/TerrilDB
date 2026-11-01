import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError, api } from '../api/client'
import type { User } from '../api/types'
import { useSessionStore } from './session'

vi.mock('../api/client', async (importOriginal) => ({ ...(await importOriginal<typeof import('../api/client')>()), api: vi.fn() }))

const alice: User = { id: '1', username: 'alice', email: 'alice@exemple.test', totpEnabled: false, recoveryCodesLeft: 0, createdAt: '2026-11-01T10:00:00+00:00' }
const mocked = vi.mocked(api)

describe('session store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    mocked.mockReset()
  })

  it('loads the current user, once', async () => {
    mocked.mockResolvedValue({ user: alice })
    const session = useSessionStore()

    await session.load()
    await session.load()

    expect(session.user).toEqual(alice)
    expect(session.loaded).toBe(true)
    expect(mocked).toHaveBeenCalledTimes(1)
  })

  it('stays anonymous when nobody is logged in', async () => {
    mocked.mockRejectedValue(new ApiError(401, 'unauthenticated'))
    const session = useSessionStore()

    await session.load()

    expect(session.user).toBeNull()
    expect(session.loaded).toBe(true)
  })

  it('logs in with the login, the password and the optional code', async () => {
    mocked.mockResolvedValue({ user: alice, recoveryCodeUsed: false })
    const session = useSessionStore()

    const result = await session.login('alice', 'a long enough password', '123456')

    expect(mocked).toHaveBeenCalledWith('POST', '/web/login', { login: 'alice', password: 'a long enough password', code: '123456' })
    expect(session.user).toEqual(alice)
    expect(result.recoveryCodeUsed).toBe(false)
  })

  it('leaves out the code when there is none', async () => {
    mocked.mockResolvedValue({ user: alice, recoveryCodeUsed: false })

    await useSessionStore().login('alice', 'a long enough password')

    expect(mocked).toHaveBeenCalledWith('POST', '/web/login', { login: 'alice', password: 'a long enough password' })
  })

  it('keeps anonymous and rethrows when the login fails', async () => {
    mocked.mockRejectedValue(new ApiError(401, 'second_factor_required'))
    const session = useSessionStore()

    await expect(session.login('alice', 'x')).rejects.toMatchObject({ code: 'second_factor_required' })
    expect(session.user).toBeNull()
  })

  it('logs out locally even when the server cannot be reached', async () => {
    mocked.mockResolvedValueOnce({ user: alice, recoveryCodeUsed: false })
    const session = useSessionStore()
    await session.login('alice', 'x')

    mocked.mockRejectedValueOnce(new ApiError(0, 'network'))
    await session.logout()

    expect(session.user).toBeNull()
  })

  it('forgets the user when the session is lost', async () => {
    mocked.mockResolvedValue({ user: alice, recoveryCodeUsed: false })
    const session = useSessionStore()
    await session.login('alice', 'x')

    session.dropUser()

    expect(session.user).toBeNull()
  })

  it('enrols a second factor and shows the recovery codes once', async () => {
    mocked.mockResolvedValueOnce({ user: alice, recoveryCodeUsed: false })
    const session = useSessionStore()
    await session.login('alice', 'x')

    mocked.mockResolvedValueOnce({ secret: 'ABCDEF', uri: 'otpauth://totp/x' })
    const pending = await session.beginTotp()
    expect(pending.secret).toBe('ABCDEF')

    mocked.mockResolvedValueOnce({ recoveryCodes: ['aaaa-bbbb-cccc-dddd'] })
    mocked.mockResolvedValueOnce({ user: { ...alice, totpEnabled: true, recoveryCodesLeft: 1 } })
    const codes = await session.confirmTotp('ABCDEF', '123456')

    expect(codes).toEqual(['aaaa-bbbb-cccc-dddd'])
    expect(session.user?.totpEnabled).toBe(true)
  })

  it('refreshes the user after a password change', async () => {
    mocked.mockResolvedValueOnce({ user: alice, recoveryCodeUsed: false })
    const session = useSessionStore()
    await session.login('alice', 'x')

    mocked.mockResolvedValueOnce({ user: { ...alice, username: 'alice' } })
    await session.changePassword('old password here', 'a brand new passphrase')

    expect(mocked).toHaveBeenLastCalledWith('POST', '/web/account/password', { current: 'old password here', new: 'a brand new passphrase' })
  })

  it('disables the second factor with the password', async () => {
    mocked.mockResolvedValueOnce({ user: { ...alice, totpEnabled: true, recoveryCodesLeft: 10 }, recoveryCodeUsed: false })
    const session = useSessionStore()
    await session.login('alice', 'x')

    mocked.mockResolvedValueOnce(undefined)
    await session.disableTotp('the password')

    expect(mocked).toHaveBeenCalledWith('POST', '/web/account/totp/disable', { password: 'the password' })
    expect(session.user?.totpEnabled).toBe(false)
    expect(session.user?.recoveryCodesLeft).toBe(0)
  })

  it('regenerates recovery codes and updates the count', async () => {
    mocked.mockResolvedValueOnce({ user: { ...alice, totpEnabled: true, recoveryCodesLeft: 3 }, recoveryCodeUsed: false })
    const session = useSessionStore()
    await session.login('alice', 'x')

    mocked.mockResolvedValueOnce({ recoveryCodes: Array.from({ length: 10 }, (_, i) => `code-${String(i)}`) })
    const codes = await session.regenerateRecoveryCodes('the password')

    expect(codes).toHaveLength(10)
    expect(session.user?.recoveryCodesLeft).toBe(10)
  })
})
