import { apiClient } from '@/services/apiClient'
import type { PersonFilmography, PersonProfile } from '@/types/api'

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
