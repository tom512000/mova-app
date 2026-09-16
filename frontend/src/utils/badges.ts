import type { Badge, BadgeCategory } from '@/types/api'
import { ROLE_LABEL } from '@/utils/roles'

/**
 * The order the shelf's filter offers them in: what a work *is* first, then who made it.
 * Not alphabetical, and not the enum's order either — this one reads as a sentence about a
 * film going from the screen back to the credits.
 */
export const BADGE_CATEGORIES: BadgeCategory[] = [
  'genre',
  'country',
  'decade',
  'budget',
  'studio',
  'director',
  'creator',
  'writer',
  'actor',
  'producer',
]

/**
 * The five credit categories borrow the names the rest of the app already gives those jobs,
 * rather than inventing a second set. A badge saying "Interprétation" and a person's page
 * saying "Acteur" for the same thing would read as two different applications.
 */
export const BADGE_CATEGORY_LABEL: Record<BadgeCategory, string> = {
  genre: 'Genre',
  country: 'Pays',
  decade: 'Décennie',
  budget: 'Budget',
  studio: 'Studio',
  ...ROLE_LABEL,
}

/** In millions of dollars, which is the unit TMDB records budgets in. */
function millions(amount: number): string {
  return `${Math.round(amount / 1_000_000)} M$`
}

/**
 * What the badge is called.
 *
 * Most categories carry a name already — a genre, a country, a person. The two that do not
 * are worded here rather than on the backend, which sends a decade as its first year and a
 * budget bracket as its own bounds in dollars, for the same reason the budget chart words
 * its own axis: the brackets are data and their French is copy.
 */
export function badgeTitle(badge: Badge): string {
  if (badge.category === 'decade') {
    return `Années ${badge.label}`
  }

  if (badge.category === 'budget') {
    const [min, max] = badge.label.split('-')
    if (min === '0') return `Moins de ${millions(Number(max))}`
    if (max === '') return `Plus de ${millions(Number(min))}`

    return `De ${millions(Number(min))} à ${millions(Number(max))}`
  }

  return badge.label
}

/**
 * Where a badge leads, when it leads anywhere.
 *
 * Three of them do not: the library can be narrowed to a genre or a studio and a person has
 * a page, but there is no listing for a country, a decade or a budget bracket. Those stamps
 * are stamps and not links — the same reasoning the saga block uses for a film the library
 * does not hold, where a link to nothing is worth less than plain text.
 */
export function badgeHref(badge: Badge): string | null {
  switch (badge.category) {
    case 'genre':
      // The listing filters on the name, which is what the backend sends as the subject.
      return `/movies?genre=${encodeURIComponent(badge.subjectId)}`
    case 'studio':
      return `/movies?studioId=${badge.subjectId}`
    case 'country':
    case 'decade':
    case 'budget':
      return null
    default:
      return `/people/${badge.subjectId}`
  }
}
