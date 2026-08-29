import { apiClient } from '@/services/apiClient'
import type {
  ActivityStats,
  CountryStat,
  GenreStat,
  OverviewStats,
  PersonStat,
  RatingStats,
  ReleaseWindowStats,
  TimelineBucket,
} from '@/types/api'

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

export async function fetchDirectorStats(limit = 25): Promise<PersonStat[]> {
  const { data } = await apiClient.get<PersonStat[]>('/stats/directors', { params: { limit } })
  return data
}

/** Whoever series are by — a ranking of its own, since creating is not directing. */
export async function fetchCreatorStats(limit = 25): Promise<PersonStat[]> {
  const { data } = await apiClient.get<PersonStat[]>('/stats/creators', { params: { limit } })
  return data
}

export async function fetchActorStats(limit = 25): Promise<PersonStat[]> {
  const { data } = await apiClient.get<PersonStat[]>('/stats/actors', { params: { limit } })
  return data
}

export async function fetchWriterStats(limit = 25): Promise<PersonStat[]> {
  const { data } = await apiClient.get<PersonStat[]>('/stats/writers', { params: { limit } })
  return data
}

export async function fetchCountryStats(limit = 12): Promise<CountryStat[]> {
  const { data } = await apiClient.get<CountryStat[]>('/stats/countries', { params: { limit } })
  return data
}

export async function fetchActivityStats(): Promise<ActivityStats> {
  const { data } = await apiClient.get<ActivityStats>('/stats/activity')
  return data
}

export async function fetchReleaseWindowStats(): Promise<ReleaseWindowStats> {
  const { data } = await apiClient.get<ReleaseWindowStats>('/stats/at-release')
  return data
}
