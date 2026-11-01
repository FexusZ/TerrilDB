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
    items.value = [...items.value, connection].sort((a, b) => a.name.localeCompare(b.name))

    return connection
  }

  async function update(id: string, input: ConnectionInput): Promise<ConnectionSummary> {
    const { connection } = await api<{ connection: ConnectionSummary }>('PUT', `/web/connections/${id}`, input)
    items.value = items.value.map((existing) => (existing.id === id ? connection : existing)).sort((a, b) => a.name.localeCompare(b.name))

    return connection
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

  return { items, loaded, load, byId, fetch, create, update, remove, probe, testSaved, reset }
})
