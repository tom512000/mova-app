import type { PersonSortField, SortDirection } from '@/types/api'

interface PersonSortOption {
  value: PersonSortField
  label: string
  /** Mirrors PersonSortField::defaultsToDescending() so the URL always spells the order out. */
  defaultDirection: SortDirection
}

/**
 * Deliberately not the library's list under other labels: a person has no release year and
 * no runtime, and what a reader wants from a name is how much of them they have seen.
 */
export const PERSON_SORT_OPTIONS: PersonSortOption[] = [
  { value: 'works', label: 'Œuvres vues', defaultDirection: 'desc' },
  { value: 'name', label: 'Nom', defaultDirection: 'asc' },
  { value: 'rating', label: 'Ta note', defaultDirection: 'desc' },
  { value: 'recent', label: 'Vu récemment', defaultDirection: 'desc' },
  { value: 'random', label: 'Aléatoire', defaultDirection: 'asc' },
]

export function defaultPersonDirectionFor(sort: PersonSortField): SortDirection {
  return PERSON_SORT_OPTIONS.find((option) => option.value === sort)?.defaultDirection ?? 'desc'
}

export interface PersonFilterState {
  /** '' for every job, otherwise a CreditRole. */
  role: string
  /** '' for the whole library, otherwise a MediaType. */
  mediaType: string
  sort: PersonSortField
  direction: SortDirection
}
