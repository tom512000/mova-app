import { apiClient } from '@/services/apiClient'
import type { DirectorStat, GenreStat, OverviewStats, RatingStats, TimelineBucket } from '@/types/api'

export async function fetchOverviewStats(): Promise<OverviewStats> {
  const { data } = await apiClient.get<OverviewStats>('/stats/overview')
  return data
}

export async function fetchTimelineStats(granularity: 'month' | 'year'): Promise<TimelineBucket[]> {
  const { data } = await apiClient.get<TimelineBucket[]>('/stats/timeline', { params: { granularity } })
  return data
}

export async function fetchRatingStats(): Promise<RatingStats> {
  const { data } = await apiClient.get<RatingStats>('/stats/ratings')
  return data
}

export async function fetchGenreStats(): Promise<GenreStat[]> {
  const { data } = await apiClient.get<GenreStat[]>('/stats/genres')
  return data
}

export async function fetchDirectorStats(limit = 25): Promise<DirectorStat[]> {
  const { data } = await apiClient.get<DirectorStat[]>('/stats/directors', { params: { limit } })
  return data
}
