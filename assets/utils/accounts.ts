import type { ConnectionEncryption, SecurityFinding } from '../api/types'

const SEVERITIES: Record<SecurityFinding['severity'], string> = { high: 'Élevée', medium: 'Moyenne', low: 'Faible' }

export const severityLabel = (severity: SecurityFinding['severity']): string => SEVERITIES[severity]

/** Une phrase par règle ; le détail (droit, hôtes, rôles, plugin) est une donnée du serveur, montrée telle quelle. */
export function describeFinding(finding: SecurityFinding): string {
  const detail = finding.detail ?? ''
  const mine = finding.currentAccount ? ' C’est le compte de cette connexion : le dernier rempart de la lecture seule est plus faible qu’annoncé.' : ''

  switch (finding.rule) {
    case 'anonymous_account':
      return 'Compte anonyme : n’importe quel nom d’utilisateur peut s’y rattacher.'
    case 'wildcard_host':
      return 'Hôte joker (%) : le compte est joignable depuis n’importe quelle machine.'
    case 'same_name_many_hosts':
      return `Même nom sur plusieurs hôtes (${detail}) : le plus spécifique l’emporte, ce qui prête à confusion.`
    case 'no_password':
      return 'Compte sans mot de passe.'
    case 'legacy_plugin':
      return `Plugin d’authentification ancien ou déprécié (${detail}).`
    case 'disabled_plugin':
      return `Plugin d’authentification éteint sur ce serveur (${detail}) : le compte ne peut pas se connecter.`
    case 'password_expired':
      return 'Mot de passe expiré.'
    case 'dangerous_privilege':
      return `Droit dangereux : ${detail}.${mine}`
    case 'all_privileges':
      return `Tous les droits de données et de structure sur toutes les bases${detail === '' ? '' : ` (${detail})`}.${mine}`
    case 'grant_option':
      return `Peut transmettre ses droits (GRANT OPTION) sur ${detail}.${mine}`
    case 'role_never_active':
      return `Rôle accordé mais jamais activé (${detail}) : il ne donne aucun droit effectif.`
    case 'no_tls_requirement':
      return 'Joignable par le réseau sans exigence de chiffrement (TLS).'
    case 'open_test_database':
      return `Base « ${detail} » ouverte à un compte anonyme.`
    case 'incomplete_check':
      return detail === 'accounts' ? 'Vérification incomplète : le serveur a plus de comptes que la limite lue.' : 'Vérification incomplète : une partie des droits n’a pas pu être lue (liste coupée ou nom de compte ambigu), l’absence d’un droit n’est donc pas prouvée.'
    default:
      return `Constatation ${finding.rule}${detail === '' ? '' : ` (${detail})`}.`
  }
}

export function describeEncryption(encryption: ConnectionEncryption): string {
  if (!encryption.encrypted) {
    return 'La connexion de TerrilDB vers ce serveur n’est pas chiffrée.'
  }
  const parts = [encryption.protocol, encryption.cipher].filter((part): part is string => part !== null && part !== '')

  return parts.length === 0 ? 'Connexion chiffrée.' : `Connexion chiffrée (${parts.join(', ')}).`
}
