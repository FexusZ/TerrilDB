const DATE = new Intl.DateTimeFormat('fr-FR', { day: 'numeric', month: 'short', year: 'numeric' })
const NUMBER = new Intl.NumberFormat('fr-FR')

export function formatDate(iso: string): string {
  return DATE.format(new Date(iso))
}

/** Nombre de lignes estimé par le moteur ; rien quand le moteur n'en donne pas. */
export function formatEstimate(rows: number | null): string {
  return rows === null ? '' : `~ ${NUMBER.format(rows)}`
}

export function formatDuration(milliseconds: number): string {
  return milliseconds < 1000 ? `${String(milliseconds)} ms` : `${new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 2 }).format(milliseconds / 1000)} s`
}
