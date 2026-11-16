/** Même plafond que le serveur (`ScriptAnalyzer`) : au-delà, le script serait refusé de toute façon. */
export const MAX_SCRIPT_BYTES = 1_000_000

export type SqlFileFailure = 'too_large' | 'empty' | 'binary' | 'not_utf8' | 'unreadable'
export type SqlFileResult = { ok: true; text: string } | { ok: false; reason: SqlFileFailure }

/**
 * Lit un fichier SQL local, dans le navigateur seulement : rien n'est envoyé d'ici. Le texte rendu va dans l'éditeur,
 * puis passe par l'aperçu du serveur comme n'importe quel script.
 */
export async function readSqlFile(file: File): Promise<SqlFileResult> {
  if (file.size > MAX_SCRIPT_BYTES) {
    return { ok: false, reason: 'too_large' }
  }
  let bytes: Uint8Array
  try {
    bytes = new Uint8Array(await file.arrayBuffer())
  } catch {
    return { ok: false, reason: 'unreadable' }
  }
  // Un octet nul n'existe pas dans un texte SQL : c'est un fichier binaire (archive, base, image).
  if (bytes.includes(0)) {
    return { ok: false, reason: 'binary' }
  }
  let text: string
  try {
    text = new TextDecoder('utf-8', { fatal: true }).decode(bytes)
  } catch {
    return { ok: false, reason: 'not_utf8' }
  }
  if (text.trim() === '') {
    return { ok: false, reason: 'empty' }
  }

  return { ok: true, text }
}

export const SQL_FILE_MESSAGES: Record<SqlFileFailure, string> = {
  too_large: 'Ce fichier est trop gros : un script ne dépasse pas 1 Mo (et 500 instructions).',
  empty: 'Ce fichier est vide : il ne contient aucune instruction.',
  binary: 'Ce fichier n’est pas du texte (il contient des octets binaires) : choisissez un fichier .sql.',
  not_utf8: 'Ce fichier n’est pas encodé en UTF-8 : convertissez-le avant de l’importer.',
  unreadable: 'Ce fichier n’a pas pu être lu. Réessayez.',
}
