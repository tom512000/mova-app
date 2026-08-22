import { apiClient } from '@/services/apiClient'
import type { MovieListResponse } from '@/types/api'

export interface WatchlistSearchParams {
  q?: string
  page?: number
  perPage?: number
}

export async function fetchWatchlist(params: WatchlistSearchParams): Promise<MovieListResponse> {
  const { data } = await apiClient.get<MovieListResponse>('/watchlist', { params })
  return data
}
