import { apiClient } from '@/services/apiClient'
import type { MovieDetail, MovieListResponse } from '@/types/api'

export interface MovieSearchParams {
  q?: string
  genre?: string
  year?: number
  page?: number
  perPage?: number
}

export async function fetchMovies(params: MovieSearchParams): Promise<MovieListResponse> {
  const { data } = await apiClient.get<MovieListResponse>('/movies', { params })
  return data
}

export async function fetchMovie(id: number): Promise<MovieDetail> {
  const { data } = await apiClient.get<MovieDetail>(`/movies/${id}`)
  return data
}
