import type { CreditRole } from '@/types/api'

/**
 * Each job named once, for every place that has to label one.
 *
 * The names of the jobs, not of the people who hold them — "Réalisation", never
 * "Réalisateur·rice·s". Inclusive plurals are unreadable in a badge or a table cell, and
 * the surrounding context always says whose page it is anyway.
 */
export const ROLE_LABEL: Record<CreditRole, string> = {
  director: 'Réalisation',
  creator: 'Création',
  writer: 'Scénario',
  actor: 'Interprétation',
  producer: 'Production',
}

/**
 * What one entry in that job counts as. Series have creators and no directors, so a
 * creator's work is measured in series and everybody else's in films — counting a series
 * as a film is the exact mislabelling the creator role exists to undo.
 */
export function workUnit(role: CreditRole, count: number): string {
  const plural = count > 1 ? 's' : ''

  return 'creator' === role ? `série${plural}` : `film${plural}`
}
