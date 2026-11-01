const DATE = new Intl.DateTimeFormat('fr-FR', { day: 'numeric', month: 'short', year: 'numeric' })
const NUMBER = new Intl.NumberFormat('fr-FR')

const DATE_TIME = new Intl.DateTimeFormat('fr-FR', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' })

export function formatDateTime(iso: string): string {
  return DATE_TIME.format(new Date(iso))
}

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
