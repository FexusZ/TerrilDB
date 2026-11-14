import type { Engine } from '../api/types'
import catalogue from '../snippets/sql-snippets.json'

/**
 * Un squelette SQL à compléter, proposé dans l'éditeur. Les `${nom}` sont des champs à remplir : aucun nom de table réelle
 * n'y figure. `requires` est une capacité de la matrice du serveur (`GET /web/connections/{id}/capabilities`) ; `engines`
 * dit pour quels moteurs le squelette est écrit (la syntaxe peut différer d'un moteur à l'autre). `example` n'est lu que
 * par les tests d'intégration, qui remplissent les champs avec le schéma de test et vérifient l'extrait contre chaque cible.
 */
export interface Snippet {
  id: string
  label: string
  detail: string
  requires: string
  engines: Engine[]
  body: string
  example: Record<string, string>
}

export const CATALOGUE: Snippet[] = catalogue as Snippet[]

/** Les champs d'un squelette, dans l'ordre d'apparition (un nom répété compte à chaque fois). */
export function fieldNames(body: string): string[] {
  return [...body.matchAll(/\$\{(\w+)\}/g)].map((match) => match[1] ?? '')
}

/**
 * Les extraits à proposer pour ce moteur : seulement ceux dont la capacité est DÉCLARÉE par le serveur. Capacités encore
 * inconnues ou capacité absente de la réponse = masqué, jamais supposé.
 */
export function snippetsFor(engine: Engine, capabilities: Record<string, boolean> | null): Snippet[] {
  if (capabilities === null) {
    return []
  }

  return CATALOGUE.filter((snippet) => snippet.engines.includes(engine) && capabilities[snippet.requires] === true)
}
