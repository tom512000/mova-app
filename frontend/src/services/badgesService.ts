import { apiClient } from '@/services/apiClient'
import type { BadgeCategory, BadgeListResponse } from '@/types/api'

export interface BadgeSearchParams {
  /** Narrows to one kind of badge. Absent means the whole shelf. */
  category?: BadgeCategory
  page?: number
  perPage?: number
}

export async function fetchBadges(params: BadgeSearchParams): Promise<BadgeListResponse> {
  const { data } = await apiClient.get<BadgeListResponse>('/badges', { params })
  return data
}

/**
 * Enough for the strip on the profile and for the shelf's own figures: the best few badges,
 * the total, and the tally per category — which the API counts over the whole shelf however
 * few items are asked for.
 *
 * Shared between the two surfaces under one query key, so opening the shelf from the strip
 * does not re-ask. Asking for the whole shelf here instead would put fifty kilobytes on a
 * page showing five stamps.
 */
export const BADGE_SUMMARY_KEY = ['badges', 'summary'] as const

export function fetchBadgeSummary(): Promise<BadgeListResponse> {
  return fetchBadges({ perPage: 5 })
}
