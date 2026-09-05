import { apiClient } from '@/services/apiClient'
import type {
  CreditRole,
  MediaType,
  MovieDetail,
  MovieFacets,
  MovieListResponse,
  MoviePoster,
  MovieSortField,
  SortDirection,
} from '@/types/api'

export interface MovieSearchParams {
  q?: string
  genre?: string
  year?: number
  /** An exact half-star note, or 'none' for the films left unrated. */
  rating?: string
  /** ISO day; keeps films watched that very day, as counted by the activity calendar. */
  watchedOn?: string
  /** Keeps films this person is credited on, optionally narrowed to one role. */
  personId?: string
  personRole?: CreditRole
  /** Keeps films this production company is credited on. */
  studioId?: string
  /** Films only, or series only. Absent means the whole library. */
  mediaType?: MediaType
  sort?: MovieSortField
  direction?: SortDirection
  /** Keeps a random sort stable from one page to the next. */
  seed?: string
  page?: number
  perPage?: number
}

export async function fetchMovies(params: MovieSearchParams): Promise<MovieListResponse> {
  const { data } = await apiClient.get<MovieListResponse>('/movies', { params })
  return data
}

export async function fetchMovieFacets(): Promise<MovieFacets> {
  const { data } = await apiClient.get<MovieFacets>('/movies/facets')
  return data
}

export async function fetchMovie(id: string): Promise<MovieDetail> {
  const { data } = await apiClient.get<MovieDetail>(`/movies/${id}`)
  return data
}

/**
 * Every poster the profile owns, unpaged: the museum wall is one continuous surface, so a
 * page boundary in the middle of it would be a wall you cannot walk past.
 */
export async function fetchPosterWall(
  sort: MovieSortField,
  direction: SortDirection,
  seed?: string,
  mediaType?: MediaType
) {
  const { data } = await apiClient.get<{ items: MoviePoster[]; total: number }>('/movies/posters', {
    params: { sort, direction, seed, mediaType },
  })
  return data.items
}
