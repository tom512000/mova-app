import { apiClient } from '@/services/apiClient'
import type { MovieListResponse, MovieSummary, WatchlistFacets, WatchlistSortField } from '@/types/api'

export interface WatchlistSearchParams {
  q?: string
  /** The evening's budget in minutes. Films of unknown length are left out, not guessed at. */
  maxRuntime?: number
  genre?: string
  /** First year of the decade, e.g. 1990. */
  decade?: number
  sort?: WatchlistSortField
  direction?: 'asc' | 'desc'
  page?: number
  perPage?: number
}

export async function fetchWatchlist(params: WatchlistSearchParams): Promise<MovieListResponse> {
  const { data } = await apiClient.get<MovieListResponse>('/watchlist', { params })
  return data
}

export async function fetchWatchlistFacets(): Promise<WatchlistFacets> {
  const { data } = await apiClient.get<WatchlistFacets>('/watchlist/facets')
  return data
}

/**
 * One film among those the current filters keep.
 *
 * Drawn server-side: the browser only holds the page it is looking at, so picking here would
 * quietly bias the answer towards whatever the sort put first.
 */
export async function pickFromWatchlist(params: WatchlistSearchParams): Promise<MovieSummary | null> {
  const { data } = await apiClient.get<{ movie: MovieSummary | null }>('/watchlist/pick', { params })
  return data.movie
}
