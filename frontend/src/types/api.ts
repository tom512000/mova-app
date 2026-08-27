export type EnrichmentStatus = 'pending' | 'enriched' | 'failed' | 'ambiguous' | 'excluded'
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

export type GameKind = 'clue' | 'compare'
export type GameMode = 'daily' | 'infinite'
export type GameStatus = 'in_progress' | 'won' | 'lost'
export type FacetMatch = 'exact' | 'close' | 'none' | 'unknown'

export interface GameClue {
  label: string
  value: string
}

/** One value of a list-shaped attribute, judged on its own. Only ever exact or none. */
export interface FacetPart {
  value: string
  match: FacetMatch
}

/**
 * One attribute of a guessed film, already judged server-side. The target's own values are
 * never sent — only this verdict — so the answer cannot be read off the wire.
 */
export interface ComparisonFacet {
  label: string
  value: string
  match: FacetMatch
  /** 'up' when the answer's number is higher than the guess's, 'down' when lower. */
  direction: 'up' | 'down' | null
  /** Present on list-shaped attributes: genres, countries, studios, names. */
  parts: FacetPart[] | null
}

export interface GameGuess {
  movieId: number
  title: string
  releaseYear: number | null
  posterUrl: string | null
  correct: boolean
  /** Populated in the comparison game only. */
  facets: ComparisonFacet[] | null
}

/**
 * The board as the player is allowed to see it: `clues` holds only what has been unlocked,
 * and `answer` stays null until the run is over.
 */
export interface GameState {
  game: GameKind
  mode: GameMode
  status: GameStatus
  attemptsUsed: number
  maxAttempts: number
  clues: GameClue[]
  guesses: GameGuess[]
  answer: MovieSummary | null
  puzzleDate: string | null
}

export type CreditRole = 'director' | 'writer' | 'actor'

/** The person a listing was narrowed to, resolved server-side from the id in the URL. */
export interface PersonFilter {
  id: number
  name: string
  role: CreditRole | null
}

export type MovieSortField = 'title' | 'rating' | 'year' | 'watched' | 'added' | 'runtime' | 'random'
export type SortDirection = 'asc' | 'desc'

/** The values the current profile can actually filter on, straight from its library. */
export interface MovieFacets {
  genres: string[]
  years: number[]
  ratings: number[]
  hasUnrated: boolean
}

export interface MovieListResponse {
  items: MovieSummary[]
  total: number
  page: number
  perPage: number
  person: PersonFilter | null
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

export interface PersonStat {
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

export interface AuthUser {
  id: number
  email: string
  displayName: string
  letterboxdUsername: string | null
  rssSyncEnabled: boolean
}

export interface ProfileSummary {
  id: number
  displayName: string
  isSelf: boolean
}

export interface ShareLink {
  token: string
  createdAt: string
}

export interface ShareAcceptResult {
  profile: ProfileSummary
  alreadyGranted: boolean
}

export interface CountryStat {
  countryName: string
  isoCode: string
  movieCount: number
  averageRating: number | null
}

export interface WeekdayStat {
  weekday: number
  label: string
  watchCount: number
  averageRating: number | null
}

export interface ActivityDay {
  date: string
  watchCount: number
}

export interface ActivityStats {
  activeDays: number
  spanDays: number
  busiestDayCount: number
  busiestDate: string | null
  longestStreakDays: number
  weekdays: WeekdayStat[]
  calendar: ActivityDay[]
}
