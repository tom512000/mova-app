import { apiClient } from '@/services/apiClient'
import type { MovieDetail, MovieFacets, MovieListResponse, MovieSortField, SortDirection } from '@/types/api'

export interface MovieSearchParams {
  q?: string
  genre?: string
  year?: number
  /** An exact half-star note, or 'none' for the films left unrated. */
  rating?: string
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

export async function fetchMovie(id: number): Promise<MovieDetail> {
  const { data } = await apiClient.get<MovieDetail>(`/movies/${id}`)
  return data
}
