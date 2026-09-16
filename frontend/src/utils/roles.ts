import type { CreditRole } from '@/types/api'

/**
 * Each job named once, for every place that has to label one.
 *
 * The names of the jobs, not of the people who hold them — "Réalisation", never
 * "Réalisateur·rice·s". Inclusive plurals are unreadable in a badge or a table cell, and
 * the surrounding context always says whose page it is anyway.
 */
/**
 * Every job, in the order a credit block names them — direction first, performance last.
 *
 * Written out rather than derived from ROLE_LABEL's keys: the order is the point, and an
 * object's key order is an implementation detail nobody should have to know to read this.
 * It mirrors the declaration order of CreditRole on the backend.
 */
export const CREDIT_ROLES: CreditRole[] = ['director', 'creator', 'writer', 'actor', 'producer']

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
