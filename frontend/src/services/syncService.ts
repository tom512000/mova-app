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

/**
 * Points this account at a Letterboxd username, or clears it.
 *
 * An empty username switches syncing off altogether — the backend reads '' as "none", so
 * clearing the field is the way out rather than a state you have to invent a value to reach.
 */
export async function updateSyncSettings(settings: {
  letterboxdUsername: string | null
  rssSyncEnabled: boolean
}): Promise<SyncState> {
  const { data } = await apiClient.put<SyncState>('/sync/letterboxd', settings)
  return data
}
