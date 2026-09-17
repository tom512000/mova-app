import { apiClient } from '@/services/apiClient'
import type { Trophy } from '@/types/api'

/** Every trophy in the catalogue, won or not, in catalogue order. */
export async function fetchTrophies(): Promise<Trophy[]> {
  const { data } = await apiClient.get<Trophy[]>('/trophies')
  return data
}
