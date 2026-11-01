import { defineStore } from 'pinia'
import { ref } from 'vue'
import { ApiError, api } from '../api/client'
import type { User } from '../api/types'

export interface PendingTotp {
  secret: string
  uri: string
}

export const useSessionStore = defineStore('session', () => {
  const user = ref<User | null>(null)
  const loaded = ref(false)

  async function load(): Promise<void> {
    if (loaded.value) {
      return
    }
    try {
      user.value = (await api<{ user: User }>('GET', '/web/me')).user
    } catch (error) {
      if (!(error instanceof ApiError) || error.status !== 401) {
        throw error
      }
      user.value = null
    }
    loaded.value = true
  }

  async function login(login: string, password: string, code?: string): Promise<{ recoveryCodeUsed: boolean }> {
    const body: Record<string, string> = { login, password }
    if (code !== undefined && code !== '') {
      body.code = code
    }
    const result = await api<{ user: User; recoveryCodeUsed: boolean }>('POST', '/web/login', body)
    user.value = result.user
    loaded.value = true

    return { recoveryCodeUsed: result.recoveryCodeUsed }
  }

  async function register(email: string, username: string, password: string, invitation?: string): Promise<void> {
    await api('POST', '/web/register', invitation === undefined ? { email, username, password } : { email, username, password, invitation })
  }

  async function logout(): Promise<void> {
    try {
      await api('POST', '/web/logout')
    } catch {
      // Déconnecté localement quoi qu'il arrive : un serveur injoignable ne doit pas garder l'écran ouvert.
    } finally {
      user.value = null
    }
  }

  /** La session n'existe plus côté serveur : on l'oublie ici aussi. */
  function dropUser(): void {
    user.value = null
    loaded.value = true
  }

  async function refresh(): Promise<void> {
    user.value = (await api<{ user: User }>('GET', '/web/me')).user
  }

  async function changePassword(current: string, newPassword: string): Promise<void> {
    user.value = (await api<{ user: User }>('POST', '/web/account/password', { current, new: newPassword })).user
  }

  function beginTotp(): Promise<PendingTotp> {
    return api<PendingTotp>('POST', '/web/account/totp/begin')
  }

  async function confirmTotp(secret: string, code: string): Promise<string[]> {
    const { recoveryCodes } = await api<{ recoveryCodes: string[] }>('POST', '/web/account/totp/confirm', { secret, code })
    await refresh()

    return recoveryCodes
  }

  async function disableTotp(password: string): Promise<void> {
    await api('POST', '/web/account/totp/disable', { password })
    if (user.value !== null) {
      user.value = { ...user.value, totpEnabled: false, recoveryCodesLeft: 0 }
    }
  }

  async function regenerateRecoveryCodes(password: string): Promise<string[]> {
    const { recoveryCodes } = await api<{ recoveryCodes: string[] }>('POST', '/web/account/recovery-codes', { password })
    if (user.value !== null) {
      user.value = { ...user.value, recoveryCodesLeft: recoveryCodes.length }
    }

    return recoveryCodes
  }

  /** Rend la phrase : elle n'est montrée qu'une fois et n'est jamais gardée dans le magasin. */
  async function createRecoveryPhrase(password: string): Promise<string> {
    const { phrase } = await api<{ phrase: string }>('POST', '/web/account/recovery-phrase', { password })
    if (user.value !== null) {
      user.value = { ...user.value, recoveryPhrase: true }
    }

    return phrase
  }

  async function removeRecoveryPhrase(password: string): Promise<void> {
    await api('POST', '/web/account/recovery-phrase/disable', { password })
    if (user.value !== null) {
      user.value = { ...user.value, recoveryPhrase: false }
    }
  }

  /** Mot de passe oublié : ne connecte pas, l'utilisateur se connecte ensuite avec le nouveau mot de passe. */
  async function recover(login: string, phrase: string, newPassword: string): Promise<void> {
    await api('POST', '/web/recover', { login, phrase, newPassword })
  }

  return { user, loaded, load, login, register, logout, dropUser, refresh, changePassword, beginTotp, confirmTotp, disableTotp, regenerateRecoveryCodes, createRecoveryPhrase, removeRecoveryPhrase, recover }
})
