import { apiClient } from '@/services/apiClient'
import type { SyncState } from '@/types/api'

export async function fetchSyncState(): Promise<SyncState> {
  const { data } = await apiClient.get<SyncState>('/sync/letterboxd')
  return data
}

export async function triggerSync(): Promise<SyncState> {
  const { data } = await apiClient.post<SyncState>('/sync/letterboxd')
  return data
}
