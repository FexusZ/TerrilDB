import type { HealthIndicator } from '../api/health'

interface Described {
  label: string
  /** « Comment le lire » : ce que la valeur veut dire et ce qu'elle ne prouve pas. */
  hint: string
}

const KNOWN: Record<string, Described> = {
  queries_per_second: { label: 'Requêtes par seconde', hint: 'Débit sur l’intervalle mesuré. Seule une comparaison avec votre charge habituelle lui donne un sens.' },
  connections_used_percent: { label: 'Connexions / plafond', hint: 'Connexions ouvertes rapportées au plafond du serveur. Repère : 80 %. Beaucoup de sessions en sommeil plaident pour un pool de connexions plutôt que pour un plafond plus haut.' },
  threads_running: { label: 'Fils en exécution', hint: 'Requêtes réellement en cours à cet instant (pas les connexions ouvertes). Repère : 50. Une valeur élevée peut être normale sur un gros serveur.' },
  aborted_connects_percent: { label: 'Échecs de connexion', hint: 'Part des tentatives de connexion qui échouent (mot de passe, droits, délai). Repère : 5 %, jugé à partir de 10 tentatives.' },
  buffer_pool_hit_percent: { label: 'Accès servis par le cache InnoDB', hint: 'Part des lectures de pages trouvées en mémoire sur l’intervalle. Repère : 99 %, jugé à partir de 1000 demandes. Un cache froid après un redémarrage donne une valeur basse sans gravité.' },
  tmp_disk_tables_percent: { label: 'Tables temporaires sur disque', hint: 'Part des tables temporaires internes créées sur disque plutôt qu’en mémoire. Repère : 25 %, jugé à partir de 100 créations. Souvent un tri ou un regroupement sans index.' },
  row_lock_waits_per_second: { label: 'Attentes de verrous de ligne', hint: 'Fréquence des attentes sur l’intervalle ; avertissement si l’attente moyenne atteint une seconde.' },
  slow_queries_per_second: { label: 'Requêtes lentes par seconde', hint: 'Requêtes dépassant le seuil du journal des requêtes lentes du serveur. Repère : 1 par seconde.' },
  redo_log_waits: { label: 'Attentes du journal de reprise', hint: 'Fois où une écriture a dû attendre de la place dans le journal de reprise (redo) sur l’intervalle : un journal trop petit pour le débit d’écriture.' },
  transactions_per_second: { label: 'Transactions par seconde', hint: 'Validations et annulations sur la base de la connexion, sur l’intervalle.' },
  rollback_percent: { label: 'Transactions annulées', hint: 'Part des transactions annulées. Repère : 10 %, jugé à partir de 100 transactions. Un pic peut venir d’erreurs applicatives ou d’interblocages.' },
  cache_hit_percent: { label: 'Blocs servis par le cache partagé', hint: 'Part des blocs lus trouvés dans le cache de PostgreSQL ; le cache du système peut en servir d’autres. Repère : 99 %, jugé à partir de 1000 blocs.' },
  temp_files_per_second: { label: 'Fichiers temporaires par seconde', hint: 'Tris ou jointures qui débordent de la mémoire de travail. Repère : 1 par seconde.' },
  deadlocks: { label: 'Interblocages', hint: 'Interblocages détectés sur l’intervalle (au moins 1 : avertissement).' },
  rows_returned_per_second: { label: 'Lignes lues par seconde', hint: 'Lignes parcourues par les requêtes, index et parcours complets confondus.' },
}

export function describeIndicator(key: string): Described {
  return KNOWN[key] ?? { label: key, hint: 'Indicateur sans description.' }
}

const number = new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 2 })

/** La valeur avec son unité ; « inconnu » (jamais 0) quand le serveur n’a pas de mesure. */
export function formatIndicator(indicator: HealthIndicator): string {
  if (indicator.value === null) {
    return 'inconnu'
  }
  const value = number.format(indicator.value).replace(/ /g, ' ')

  switch (indicator.unit) {
    case 'per_second':
      return `${value} /s`
    case 'percent':
      return `${value} %`
    case 'count':
      return value
  }
}
