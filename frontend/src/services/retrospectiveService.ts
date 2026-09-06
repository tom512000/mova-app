import { apiClient } from '@/services/apiClient'
import type { RetrospectivePage } from '@/types/api'

/**
 * The whole page in one request, years included.
 *
 * The year is optional and read forgivingly: an unknown one falls back to the most recent
 * year that has anything in it, so a stale bookmark still shows a page.
 */
export async function fetchRetrospective(year?: number): Promise<RetrospectivePage> {
  const { data } = await apiClient.get<RetrospectivePage>('/stats/retrospective', {
    params: year === undefined ? undefined : { year },
  })
  return data
}
