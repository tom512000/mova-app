export type EnrichmentStatus = 'pending' | 'enriched' | 'failed' | 'ambiguous' | 'excluded'

/**
 * Films and the handful of series Letterboxd accepts share one catalogue, one card and one
 * detail page — this is what tells them apart where it matters.
 */
export type MediaType = 'movie' | 'series'

/**
 * Every row in the database is keyed on a UUIDv7, so an id is text on this side —
 * never a number, and never arithmetic. It sorts by creation order all the same,
 * because a v7's leading bits are a timestamp.
 */
export type Id = string
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
  id: string
  title: string
  releaseYear: number | null
  posterUrl: string | null
  myAverageRating: number | null
  watchCount: number
  /** Only the watchlist shows it: whether the film fits into the evening that is left. */
  runtimeMinutes: number | null
  enrichmentStatus: EnrichmentStatus
  mediaType: MediaType
}

export type GameKind =
  | 'clue'
  | 'compare'
  | 'poster'
  | 'hangman'
  | 'tagline'
  | 'backdrop'
  | 'duel'
  | 'timeline'
export type GameMode = 'daily' | 'infinite'
export type GameStatus = 'in_progress' | 'won' | 'lost' | 'revealed'
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
  movieId: string
  title: string
  releaseYear: number | null
  posterUrl: string | null
  correct: boolean
  /** Populated in the comparison game only. */
  facets: ComparisonFacet[] | null
}

/**
 * The answer's artwork at the sharpness earned so far — its poster in "Le film pixelisé",
 * its backdrop in "Le décor". The grid is the whole payload — no URL, no full-size image to
 * un-blur — so there is nothing here to cheat with.
 */
export interface ArtworkPixels {
  width: number
  height: number
  /** Which rung of the ladder, 1-based, and how many there are. */
  step: number
  steps: number
  /** Row-major '#rrggbb', exactly width × height of them. */
  colors: string[]
}

/**
 * The masked title. The title itself is never sent — `chars` holds one slot per character,
 * null while that letter is still to be found, so there is nothing to read off the wire.
 */
export interface HangmanBoard {
  /** null = hidden. Spaces, digits and punctuation are present from the start. */
  chars: (string | null)[]
  /** Every letter played, in order. */
  tried: string[]
  /** The ones the title does not contain. */
  wrong: string[]
  livesLeft: number
  lives: number
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
  /** The film's own marketing line, in "L'accroche" only — where it is the opening card. */
  tagline: string | null
  /** Populated in the two pixel games. Null when TMDB's artwork could not be read. */
  artwork: ArtworkPixels | null
  /** Populated in the hangman only. */
  hangman: HangmanBoard | null
  /** Populated in the duel only. */
  duel: DuelBoard | null
  /** Populated in the timeline only. */
  timeline: TimelineBoard | null
}

/**
 * One side of a duel. Thinner than MovieSummary on purpose: the summary carries the
 * rating, and in this game the rating is the answer — so it arrives null until the round
 * has been settled.
 */
export interface DuelCard {
  movieId: string
  title: string
  releaseYear: number | null
  posterUrl: string | null
  rating: number | null
}

/** A duel already played, both ratings now visible. */
export interface DuelRound {
  /** Exactly two, in the order they were shown. */
  cards: DuelCard[]
  pickedId: string
  correct: boolean
}

export interface DuelBoard {
  /** Exactly two while the run is open, null once it is over. */
  cards: DuelCard[] | null
  /** Oldest first; the losing round is the last one. */
  history: DuelRound[]
  streak: number
  /** The longest streak this profile has ever run up in this mode. */
  best: number
}

/** One film to place on the timeline. The year is the answer, so it stays null until the end. */
export interface TimelineCard {
  movieId: string
  title: string
  posterUrl: string | null
  releaseYear: number | null
}

/**
 * One submitted ordering, and the only thing said back about it: which slots were right.
 * Not which film belongs where, and not whether one is too early — either would collapse
 * the puzzle in a single move.
 */
export interface TimelineAttempt {
  order: string[]
  /** One per slot, aligned with `order`. */
  correct: boolean[]
  correctCount: number
}

export interface TimelineBoard {
  /** As dealt, never re-shuffled mid-run. */
  cards: TimelineCard[]
  attempts: TimelineAttempt[]
  /** The film ids in true release order, revealed only once the run is over. */
  solution: string[] | null
}

/**
 * 'creator' is who a series is *by*, which is not the job 'director' names — TMDB keeps them
 * apart too, and so does the ranking of most-watched directors.
 */
export type CreditRole = 'director' | 'creator' | 'writer' | 'actor'

/** The person a listing was narrowed to, resolved server-side from the id in the URL. */
export interface PersonFilter {
  id: string
  name: string
  role: CreditRole | null
}

/** One exhibit on the museum wall. Thinner than MovieSummary — the wall loads all of them. */
export interface MoviePoster {
  id: string
  title: string
  releaseYear: number | null
  /** Always present: the wall only ever holds films that have artwork. */
  posterUrl: string
  myAverageRating: number | null
  mediaType: MediaType
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
  personId: string
  name: string
  profileUrl: string | null
  characterName: string | null
}

export interface Watch {
  id: string
  watchedDate: string | null
  rating: number | null
  isRewatch: boolean
  /** Worked out from a ratings.csv date moving, rather than declared in a diary entry. */
  isDeduced: boolean
  reviewText: string | null
  containsSpoilers: boolean
  tags: string[]
}

export interface MovieDetail {
  id: string
  title: string
  originalTitle: string | null
  releaseYear: number | null
  /** A film's running time; a series' total across every episode. */
  runtimeMinutes: number | null
  synopsis: string | null
  posterUrl: string | null
  backdropUrl: string | null
  tmdbVoteAverage: number | null
  imdbId: string | null
  enrichmentStatus: EnrichmentStatus
  genres: string[]
  countries: string[]
  /** Always empty on a series: it has no director of record. */
  directors: Credit[]
  /** Who a series is by. Always empty on a film. */
  creators: Credit[]
  cast: Credit[]
  watches: Watch[]
  mediaType: MediaType
  /** Series only; null on a film. */
  seasonCount: number | null
  episodeCount: number | null
  lastAirDate: string | null
}

export interface MovieRuntime {
  movieId: string
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
  personId: string
  name: string
  movieCount: number
  averageRating: number | null
  bestRating: number | null
  worstRating: number | null
}

/** One of the four films pinned to the top of a Letterboxd profile. */
export interface FavouriteFilm {
  movieId: string
  title: string
  releaseYear: number | null
  posterUrl: string | null
  /** 1-based, in the order Letterboxd lists them. */
  position: number
}

/**
 * The Letterboxd page behind an imported library, as profile.csv described it.
 *
 * Everything but the dates is nullable: Letterboxd asks for none of these fields, so a
 * profile carrying nothing but a username is ordinary and the panel has to read well that
 * way rather than showing a grid of dashes.
 */
export interface LetterboxdProfile {
  username: string | null
  fullName: string | null
  location: string | null
  website: string | null
  bio: string | null
  pronoun: string | null
  joinedOn: string | null
  favourites: FavouriteFilm[]
  /** When the profile.csv this came from was imported. */
  importedAt: string
}

export interface ImportRowErrorItem {
  rowNumber: number
  errorMessage: string
}

export interface ImportBatch {
  id: string
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
  id: string
  email: string
  displayName: string
  letterboxdUsername: string | null
  rssSyncEnabled: boolean
}

export interface ProfileSummary {
  id: string
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

/** A film seen while it was still new, and the gap that earned it the place. */
export interface ReleaseWindowMovie {
  movieId: string
  title: string
  releaseYear: number | null
  releaseDate: string
  /** The first viewing: a rewatch cannot make a film "seen at release". */
  firstWatchedDate: string
  daysAfterRelease: number
}

export interface ReleaseWindowStats {
  /** The window, in days after release. */
  withinDays: number
  count: number
  /** How many of those landed inside the first week. */
  firstWeek: number
  /** Films that could qualify at all — those TMDB has a release date for. */
  comparable: number
  /** Closest to release first. */
  movies: ReleaseWindowMovie[]
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

/** How a watchlist is ordered. Shorter than MovieSortField: nothing here has been watched. */
export type WatchlistSortField = 'added' | 'title' | 'year' | 'runtime'

/**
 * What the watchlist itself can be narrowed by — never the whole library, so no dropdown
 * offers a choice that would empty the grid.
 */
export interface WatchlistFacets {
  genres: string[]
  /** First year of each decade present, newest first. */
  decades: number[]
  shortestRuntime: number | null
  longestRuntime: number | null
}
