import { defineStore } from 'pinia'
import { ref } from 'vue'
import { api } from '../api/client'
import type { ConnectionInput, ConnectionParams, ConnectionSummary } from '../api/types'

export const useConnectionsStore = defineStore('connections', () => {
  const items = ref<ConnectionSummary[]>([])
  const loaded = ref(false)

  async function load(): Promise<void> {
    items.value = (await api<{ connections: ConnectionSummary[] }>('GET', '/web/connections')).connections
    loaded.value = true
  }

  function byId(id: string): ConnectionSummary | undefined {
    return items.value.find((connection) => connection.id === id)
  }

  async function fetch(id: string): Promise<{ connection: ConnectionSummary; parameters: ConnectionParams }> {
    return api('GET', `/web/connections/${id}`)
  }

  async function create(input: ConnectionInput): Promise<ConnectionSummary> {
    const { connection } = await api<{ connection: ConnectionSummary }>('POST', '/web/connections', input)
    // Le serveur met une nouvelle connexion à la fin de la liste choisie par son propriétaire.
    items.value = [...items.value, connection]

    return connection
  }

  async function update(id: string, input: ConnectionInput): Promise<ConnectionSummary> {
    const { connection } = await api<{ connection: ConnectionSummary }>('PUT', `/web/connections/${id}`, input)
    items.value = items.value.map((existing) => (existing.id === id ? connection : existing))

    return connection
  }

  /**
   * Nouvel ordre de la liste (identifiants complets, dans l'ordre voulu) : affiché tout de suite, puis confirmé au
   * serveur. S'il le refuse (une connexion supprimée ailleurs…), on revient à ce qu'il dit.
   */
  async function reorder(ids: string[]): Promise<void> {
    const byId = new Map(items.value.map((connection) => [connection.id, connection]))
    items.value = ids.flatMap((id) => byId.get(id) ?? [])
    try {
      await api('PUT', '/web/connections/order', { ids })
    } catch {
      await load()
    }
  }

  /** Décale une connexion d'un cran (-1 vers le haut, 1 vers le bas) ; rien aux extrémités. */
  async function move(id: string, step: -1 | 1): Promise<void> {
    const ids = items.value.map((connection) => connection.id)
    const from = ids.indexOf(id)
    const to = from + step
    if (from < 0 || to < 0 || to >= ids.length) {
      return
    }
    ids.splice(to, 0, ...ids.splice(from, 1))
    await reorder(ids)
  }

  /** Dépose une connexion à la place d'une autre (glisser-déposer). */
  async function moveOnto(id: string, target: string): Promise<void> {
    const ids = items.value.map((connection) => connection.id)
    const from = ids.indexOf(id)
    const to = ids.indexOf(target)
    if (from < 0 || to < 0 || from === to) {
      return
    }
    ids.splice(to, 0, ...ids.splice(from, 1))
    await reorder(ids)
  }

  async function remove(id: string): Promise<void> {
    await api('DELETE', `/web/connections/${id}`)
    items.value = items.value.filter((connection) => connection.id !== id)
  }

  /** Essaie des paramètres sans rien enregistrer. */
  async function probe(input: ConnectionInput): Promise<number> {
    return (await api<{ durationMs: number }>('POST', '/web/connections/test', input)).durationMs
  }

  async function testSaved(id: string): Promise<number> {
    return (await api<{ durationMs: number }>('POST', `/web/connections/${id}/test`)).durationMs
  }

  function reset(): void {
    items.value = []
    loaded.value = false
  }

  return { items, loaded, load, byId, fetch, create, update, reorder, move, moveOnto, remove, probe, testSaved, reset }
})
