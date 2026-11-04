import { apiClient } from '@/services/apiClient'
import type {
  ListEntrySortField,
  MediaType,
  MovieListDetail,
  MovieListEntriesResponse,
  MovieListSummary,
  SortDirection,
} from '@/types/api'

export interface ListEntryParams {
  q?: string
  /** What the viewed profile has seen, or has not. Absent means the whole list. */
  status?: 'watched' | 'unwatched'
  mediaType?: MediaType
  genre?: string
  sort?: ListEntrySortField
  direction?: SortDirection
  /** Keeps a random sort stable from one page to the next. */
  seed?: string
  page?: number
  perPage?: number
}

export async function fetchLists(): Promise<MovieListSummary[]> {
  const { data } = await apiClient.get<MovieListSummary[]>('/lists')
  return data
}

export async function fetchList(id: string): Promise<MovieListDetail> {
  const { data } = await apiClient.get<MovieListDetail>(`/lists/${id}`)
  return data
}

export async function fetchListEntries(id: string, params: ListEntryParams): Promise<MovieListEntriesResponse> {
  const { data } = await apiClient.get<MovieListEntriesResponse>(`/lists/${id}/entries`, { params })
  return data
}
