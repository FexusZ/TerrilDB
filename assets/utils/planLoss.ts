import type { StructureLoss } from '../api/types'

/** Ce qu'un changement de structure fait perdre au-delà de son SQL, en français, pour la confirmation. Les codes viennent du serveur (`PlanLoss`). */
export function lossSentence(loss: StructureLoss): string {
  switch (loss.code) {
    case 'system_history':
      return `l’historique des lignes de « ${loss.subject} » (table versionnée)`
    case 'values_removed':
      return `les valeurs ${loss.values.map((value) => `« ${value} »`).join(', ')} de « ${loss.subject} » : les lignes qui les portent bloqueront le changement ou les perdront`
    default:
      return `une perte sur « ${loss.subject} »`
  }
}
