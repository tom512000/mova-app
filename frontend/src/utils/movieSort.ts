import type { CreditRole, MovieSortField, SortDirection } from '@/types/api'

/**
 * '' is the whole library. Films and series live in one catalogue and are browsed together
 * by default — narrowing to one kind is a choice, not the starting point.
 */
export const MEDIA_TYPE_OPTIONS = [
  { value: '', label: 'Tout' },
  { value: 'movie', label: 'Films' },
  { value: 'series', label: 'Séries' },
] as const

interface SortOption {
  value: MovieSortField
  label: string
  /** Mirrors MovieSortField::defaultsToDescending() so the URL always spells the order out. */
  defaultDirection: SortDirection
}

export const SORT_OPTIONS: SortOption[] = [
  { value: 'title', label: 'Titre', defaultDirection: 'asc' },
  { value: 'rating', label: 'Note', defaultDirection: 'desc' },
  { value: 'year', label: 'Année de sortie', defaultDirection: 'asc' },
  { value: 'watched', label: 'Date de visionnage', defaultDirection: 'desc' },
  { value: 'added', label: "Date d'ajout", defaultDirection: 'desc' },
  { value: 'runtime', label: 'Durée', defaultDirection: 'desc' },
  { value: 'random', label: 'Aléatoire', defaultDirection: 'asc' },
]

export function defaultDirectionFor(sort: MovieSortField): SortDirection {
  return SORT_OPTIONS.find((option) => option.value === sort)?.defaultDirection ?? 'asc'
}

/** Reads as a sentence in the filter chip: "Réalisé par Quentin Dupieux". */
export const ROLE_PREFIX: Record<CreditRole, string> = {
  director: 'Réalisé par',
  creator: 'Créé par',
  actor: 'Avec',
  writer: 'Écrit par',
  producer: 'Produit par',
}

export interface MovieFilterState {
  genre: string
  /** '' for the whole library, otherwise a MediaType. */
  mediaType: string
  /** '' for every note, 'none' for the unrated, otherwise an exact half-star value. */
  rating: string
  year: string
  sort: MovieSortField
  direction: SortDirection
}
