import { defineStore } from 'pinia'
import { ref } from 'vue'
import { api } from '../api/client'
import type { ApiTokenInfo, ApiTokenInput } from '../api/types'

export const useTokensStore = defineStore('tokens', () => {
  const items = ref<ApiTokenInfo[]>([])
  const loaded = ref(false)

  async function load(): Promise<void> {
    items.value = (await api<{ tokens: ApiTokenInfo[] }>('GET', '/web/tokens')).tokens
    loaded.value = true
  }

  /** Rend le texte du jeton : il n'est montré qu'une fois et n'est jamais gardé dans le magasin. */
  async function create(input: ApiTokenInput): Promise<string> {
    const { token, tokenInfo } = await api<{ token: string; tokenInfo: ApiTokenInfo }>('POST', '/web/tokens', input)
    items.value = [tokenInfo, ...items.value]

    return token
  }

  /** Remet à jour la liste des connexions d'un jeton « toutes mes connexions » ; son texte ne change pas. */
  async function refresh(id: string): Promise<void> {
    const { tokenInfo } = await api<{ tokenInfo: ApiTokenInfo }>('POST', `/web/tokens/${id}/refresh`)
    items.value = items.value.map((token) => (token.id === id ? tokenInfo : token))
  }

  async function revoke(id: string): Promise<void> {
    await api('DELETE', `/web/tokens/${id}`)
    items.value = items.value.filter((token) => token.id !== id)
  }

  function reset(): void {
    items.value = []
    loaded.value = false
  }

  return { items, loaded, load, create, refresh, revoke, reset }
})
