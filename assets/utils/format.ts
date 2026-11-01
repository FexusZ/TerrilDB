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

/** Le temps restant avant l'expiration d'une clé, dans la plus grande unité raisonnable. */
export function formatTtl(seconds: number | null): string {
  if (seconds === null) {
    return 'sans expiration'
  }
  if (seconds < 1) {
    return 'moins d’une seconde'
  }
  if (seconds < 60) {
    return `${String(seconds)} s`
  }
  if (seconds < 3600) {
    return `${String(Math.round(seconds / 60))} min`
  }
  if (seconds < 86400) {
    return `${String(Math.round(seconds / 3600))} h`
  }

  return `${String(Math.round(seconds / 86400))} j`
}
