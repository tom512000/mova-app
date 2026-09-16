import { apiClient } from '@/services/apiClient'
import type {
  CreditRole,
  MediaType,
  PersonFilmography,
  PersonListResponse,
  PersonProfile,
  PersonSortField,
  SortDirection,
} from '@/types/api'

export interface PersonSearchParams {
  q?: string
  /** Keeps people holding this job, and counts them on that job alone. */
  role?: CreditRole
  /** Counts films only, or series only. Absent means the whole library. */
  mediaType?: MediaType
  sort?: PersonSortField
  direction?: SortDirection
  /** Keeps a random sort stable from one page to the next. */
  seed?: string
  page?: number
  perPage?: number
}

export async function fetchPeople(params: PersonSearchParams): Promise<PersonListResponse> {
  const { data } = await apiClient.get<PersonListResponse>('/people', { params })
  return data
}

export async function fetchPerson(personId: string): Promise<PersonProfile> {
  const { data } = await apiClient.get<PersonProfile>(`/people/${personId}`)
  return data
}

/**
 * Fetched apart from the profile because it is the one part of the page that needs TMDB.
 * Null when there is nothing to show — see PersonFilmographyService on the backend.
 */
export async function fetchPersonFilmography(personId: string): Promise<PersonFilmography | null> {
  const { data } = await apiClient.get<PersonFilmography | null>(`/people/${personId}/filmography`)
  return data
}
