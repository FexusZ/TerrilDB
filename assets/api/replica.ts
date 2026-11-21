import { api } from './client'

/** Un réplica Vitess est-il apparu pour cette connexion ? (le serveur ne cherche qu'une fois par semaine) */
export async function fetchReplicaReminder(id: string): Promise<boolean> {
  return (await api<{ available: boolean }>('GET', `/web/connections/${id}/replica`)).available
}

/** Active la lecture sur réplica ; refusé (422 `replica_unavailable`) si le réplica a disparu. */
export async function enableReplicaReading(id: string): Promise<void> {
  await api<void>('POST', `/web/connections/${id}/replica/enable`)
}

/** « Plus tard » : on n'en reparle que dans une semaine. */
export async function postponeReplicaReminder(id: string): Promise<void> {
  await api<void>('POST', `/web/connections/${id}/replica/later`)
}
