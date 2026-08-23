export type EnrichmentStatus = 'pending' | 'enriched' | 'failed' | 'ambiguous'
export type ImportFileType = 'diary' | 'ratings' | 'watched' | 'reviews' | 'watchlist' | 'list'
export type ImportStatus = 'pending' | 'processing' | 'completed' | 'completed_with_errors' | 'failed'

export interface SyncState {
  configured: boolean
  autoSyncEnabled: boolean
  username: string | null
  lastSyncedAt: string | null
  lastSyncStatus: 'success' | 'failed' | null
  lastSyncError: string | null
  lastRunWatchesImported: number
}

export interface MovieSummary {
  id: number
  title: string
  releaseYear: number | null
  posterUrl: string | null
  myAverageRating: number | null
  watchCount: number
  enrichmentStatus: EnrichmentStatus
}

export interface MovieListResponse {
  items: MovieSummary[]
  total: number
  page: number
  perPage: number
}

export interface Credit {
  personId: number
  name: string
  profileUrl: string | null
  characterName: string | null
}

export interface Watch {
  id: number
  watchedDate: string | null
  rating: number | null
  isRewatch: boolean
  reviewText: string | null
  containsSpoilers: boolean
  tags: string[]
}

export interface MovieDetail {
  id: number
  title: string
  originalTitle: string | null
  releaseYear: number | null
  runtimeMinutes: number | null
  synopsis: string | null
  posterUrl: string | null
  backdropUrl: string | null
  tmdbVoteAverage: number | null
  imdbId: string | null
  enrichmentStatus: EnrichmentStatus
  genres: string[]
  countries: string[]
  directors: Credit[]
  cast: Credit[]
  watches: Watch[]
}

export interface MovieRuntime {
  movieId: number
  title: string
  runtimeMinutes: number
}

export interface OverviewStats {
  totalMovies: number
  totalWatches: number
  totalRewatches: number
  totalWatchlist: number
  averageRating: number | null
  medianRating: number | null
  totalWatchTimeMinutes: number
  averageMovieRuntimeMinutes: number | null
  longestMovie: MovieRuntime | null
  shortestMovie: MovieRuntime | null
}

export interface TimelineBucket {
  period: string
  watchCount: number
  watchTimeMinutes: number
  averageRating: number | null
}

export interface RatingDistributionPoint {
  rating: number
  count: number
}

export interface RatingStats {
  average: number | null
  median: number | null
  standardDeviation: number | null
  distribution: RatingDistributionPoint[]
}

export interface GenreStat {
  genreName: string
  movieCount: number
  watchCount: number
  averageRating: number | null
  totalWatchTimeMinutes: number
}

export interface DirectorStat {
  personId: number
  name: string
  movieCount: number
  averageRating: number | null
  bestRating: number | null
  worstRating: number | null
}

export interface ImportRowErrorItem {
  rowNumber: number
  errorMessage: string
}

export interface ImportBatch {
  id: number
  filename: string
  fileType: ImportFileType
  status: ImportStatus
  startedAt: string
  finishedAt: string | null
  rowsTotal: number
  rowsImported: number
  rowsSkipped: number
  rowsFailed: number
  progressPercent: number
  errorsSample: ImportRowErrorItem[]
}

export interface ImportUploadResponse {
  batches: ImportBatch[]
  unsupportedFiles: string[]
}
