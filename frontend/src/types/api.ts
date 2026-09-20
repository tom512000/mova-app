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
export type CreditRole = 'director' | 'creator' | 'writer' | 'actor' | 'producer'

/** One entry of a TMDB saga, held whether or not the library owns it. */
export interface FranchiseFilm {
  tmdbId: number
  title: string
  releaseYear: number | null
  posterUrl: string | null
  /** The library's own id when it holds this film; null makes the row a dead line, not a link. */
  movieId: string | null
  watched: boolean
}

/** The saga a film belongs to. Films only - TMDB has no collections for series. */
export interface Franchise {
  id: string
  name: string
  watchedCount: number
  films: FranchiseFilm[]
}

/** The saga a listing was narrowed to, resolved server-side from the id in the URL. */
export interface FranchiseFilter {
  id: string
  name: string
}

/** The studio a listing was narrowed to, resolved server-side from the id in the URL. */
export interface StudioFilter {
  id: string
  name: string
}

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
  studio: StudioFilter | null
  franchise: FranchiseFilter | null
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
  /** The saga this film belongs to. Always null on a series - TMDB has no collections there. */
  franchise: Franchise | null
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

export interface BudgetBand {
  /** Inclusive, in US dollars; 0 on the first bracket. */
  minBudget: number
  /** Exclusive; null on the last bracket, which is open-ended. */
  maxBudget: number | null
  movieCount: number
  averageRating: number | null
}

export interface BudgetStats {
  bands: BudgetBand[]
  /** Watched works TMDB has no budget for - what the bands were NOT computed from. */
  worksWithoutBudget: number
}

export interface DecadeStat {
  /** The decade's first year: 1970 for the 1970s. */
  decade: number
  movieCount: number
  watchCount: number
  averageRating: number | null
}

export interface DivergentWork {
  movieId: string
  title: string
  yourRating: number
  /** TMDB's audience score, already halved onto the same five-star scale. */
  publicRating: number
  /** yourRating - publicRating, computed from the two rounded values so the row adds up. */
  gap: number
}

export interface DivergenceStats {
  above: DivergentWork[]
  below: DivergentWork[]
  /** The TMDB vote floor a work had to clear to be compared at all. */
  minimumVotes: number
  /** How many works cleared it, which is what the two top fives were picked from. */
  comparableCount: number
}

/** A saga started and not finished, as counted for the dashboard. */
export interface FranchiseStat {
  franchiseId: string
  name: string
  watchedCount: number
  /**
   * Films counted in the saga, which is not always what TMDB lists: an announced film
   * nobody can have watched is left out unless the request asked for it.
   */
  totalCount: number
  /** Films not out yet, reported whether or not they were counted above. */
  upcomingCount: number
  /** Counted and unwatched, oldest first, capped for display. */
  missing: string[]
  /** Not out yet, same order and same cap. */
  upcoming: string[]
}

export interface GenreStat {
  genreName: string
  movieCount: number
  watchCount: number
  averageRating: number | null
  totalWatchTimeMinutes: number
}

/**
 * No best and worst rating, unlike PersonStat: a studio credited on a hundred films has a
 * best of 5 and a worst of 0.5 whatever it made, so the pair says nothing about it.
 */
export interface StudioStat {
  studioId: string
  name: string
  movieCount: number
  averageRating: number | null
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

/** The shelves trophies are grouped on. More arrive as more of the catalogue is built. */
export type TrophyFamily = 'regularity' | 'special_dates'

export type TrophyKey =
  | 'groundhog_day'
  | 'weekends'
  | 'dirty_dozen'
  | 'return_of_the_jedi'
  | 'old_timers'
  | 'christmas'
  | 'new_year'
  | 'valentine'
  | 'easter'
  | 'labour_day'
  | 'bastille_day'
  | 'halloween'
  | 'friday_the_13th'
  | 'leap_day'

/**
 * One trophy, won or not. Unlike badges, locked ones come back too: there are fourteen, and
 * a trophy not yet won is something to go and get rather than something silently missing.
 */
export interface Trophy {
  key: TrophyKey
  family: TrophyFamily
  /** The rungs, ascending. A single rung means the trophy is won once and has no levels. */
  tiers: number[]
  /**
   * What the rungs are measured against — the longest run of days, full weekends, the longest
   * pause, years, or days that fell on the date. Reported even while locked.
   */
  value: number
  /** Rungs reached. Zero means locked. */
  level: number
  /** The day the current rung was reached. Null while locked. */
  earnedOn: string | null
}

/**
 * What a badge is earned on. The last five mirror CreditRole one for one: somebody who acts
 * in twenty films and directs five has earned two different things.
 */
export type BadgeCategory =
  | 'genre'
  | 'country'
  | 'decade'
  | 'budget'
  | 'studio'
  | 'director'
  | 'creator'
  | 'writer'
  | 'actor'
  | 'producer'

/**
 * One badge, at the level it currently stands.
 *
 * A subject rather than a trophy per threshold: "Comédie" is one badge that climbs, which
 * is what the number in the stamp's corner is for. Nothing about it is stored — the whole
 * shelf is derived from the watch rows on every request.
 */
export interface Badge {
  category: BadgeCategory
  /** What it is about, as the client needs it to link back. Not all of them lead anywhere. */
  subjectId: string
  /** The subject's own name. Budget brackets carry their bounds; the client words them. */
  label: string
  level: number
  workCount: number
  /** How many more works the next level needs. Never zero: there is always a next one. */
  worksToNextLevel: number
  /** The watch date of the work that crossed the current level, not the day it was read. */
  earnedOn: string | null
  /** A still from one of the works that earned it, drawn stably from the badge's subject. */
  imageUrl: string | null
}

export interface BadgeListResponse {
  items: Badge[]
  total: number
  page: number
  perPage: number
  /** Null while the shelf is narrowed: it has nothing to say about what it excluded. */
  counts: Record<BadgeCategory, number> | null
}

/**
 * Somebody met for the first time in a given year, and what came of it.
 *
 * Not the retrospective's person of the year, which asks who filled a year whether or not
 * they were new: this one only counts people with no earlier work at all in the library.
 */
export interface Discovery {
  personId: string
  name: string
  profileUrl: string | null
  /** Direction wins over performance when somebody does both — the stronger claim. */
  role: CreditRole
  workCount: number
  averageRating: number | null
  firstSeenOn: string
}

/** How the directory of people is ordered. A person has no release year and no runtime. */
export type PersonSortField = 'name' | 'works' | 'rating' | 'recent' | 'random'

/**
 * One card in the directory of people.
 *
 * Every figure is narrowed by whatever narrowed the listing: filtered on "Réalisation", a
 * name that also acts comes back with its directing tally and its directing average, never
 * a blend of the two.
 */
export interface PersonSummary {
  id: string
  name: string
  profileUrl: string | null
  /** Every job they hold on the works counted here, in credit-block order. */
  roles: CreditRole[]
  /** Distinct works watched — the same tally the dashboard rankings use. */
  watchedCount: number
  /** Works of theirs waiting in the watchlist, never watched. */
  watchlistCount: number
  /** Both of the above together: everything of theirs the library holds. */
  workCount: number
  /** Averaged per work, so a film seen four times weighs once. */
  averageRating: number | null
  lastWatchedDate: string | null
}

export interface PersonListResponse {
  items: PersonSummary[]
  total: number
  page: number
  perPage: number
}

/**
 * One job a person holds in the library, counted apart from their others.
 *
 * The whole point of the person page: the same name is often two different propositions —
 * twenty-one films as an actor, seven as a director, and different notes on each.
 */
export interface PersonRole {
  role: CreditRole
  watchedCount: number
  unwatchedCount: number
  averageRating: number | null
}

/** One work of a person's that the library holds, watched or not. */
export interface PersonWork {
  movieId: string
  title: string
  releaseYear: number | null
  posterUrl: string | null
  mediaType: MediaType
  /** Several when they wore several hats on it, in credit-block order. */
  roles: CreditRole[]
  characterName: string | null
  myAverageRating: number | null
  lastWatchedDate: string | null
  watched: boolean
  inWatchlist: boolean
}

export interface PersonProfile {
  id: string
  name: string
  tmdbId: number | null
  profileUrl: string | null
  roles: PersonRole[]
  /** Distinct works watched, all jobs together — not the sum of the roles above. */
  watchedCount: number
  watchlistCount: number
  averageRating: number | null
  /** Against the profile's own average, in stars. Positive means rated above the library. */
  ratingGap: number | null
  works: PersonWork[]
}

/** One film of a person's filmography that has not been watched. */
export interface FilmographyEntry {
  tmdbId: number
  title: string
  releaseYear: number | null
  posterUrl: string | null
}

export interface FilmographyRole {
  role: CreditRole
  watchedCount: number
  totalCount: number
  /** Capped for display — the tally is watchedCount against totalCount. */
  missing: FilmographyEntry[]
}

/**
 * Comes back null whenever there is nothing worth showing: no TMDB id, TMDB unreachable, or
 * a filmography that survives none of the filters. The section simply does not draw.
 */
export interface PersonFilmography {
  roles: FilmographyRole[]
  note: string
}

/** One work named by the retrospective. */
export interface RetrospectiveWork {
  movieId: string
  title: string
  releaseYear: number | null
  posterUrl: string | null
  mediaType: MediaType
  rating: number | null
  watchedDate: string | null
}

export interface RetrospectiveMonth {
  /** 1 to 12 — named on the client, so the backend stays language-free. */
  month: number
  watchCount: number
  averageMonthCount: number
}

export interface RetrospectiveStreak {
  days: number
  startDate: string
  endDate: string
  watchCount: number
}

/**
 * Shares are of the year's viewings, so they sum past 100% — one film belongs to each of its
 * genres, the same convention the country ring uses.
 */
export interface GenreShift {
  genreName: string
  watchCount: number
  share: number
  /** Null when there is no year before to have risen from. */
  previousShare: number | null
}

export interface PersonOfTheYear {
  personId: string
  name: string
  profileUrl: string | null
  role: CreditRole
  workCount: number
}

export interface YearComparison {
  year: number
  watchCount: number
  averageRating: number | null
}

export interface Retrospective {
  year: number
  watchCount: number
  workCount: number
  activeDays: number
  totalRuntimeMinutes: number
  /** Named so the hours read as a floor rather than a total. */
  worksWithoutRuntime: number
  averageRating: number | null
  busiestMonth: RetrospectiveMonth | null
  longestStreak: RetrospectiveStreak | null
  genre: GenreShift | null
  people: PersonOfTheYear[]
  oldestDiscovery: RetrospectiveWork | null
  previousYear: YearComparison | null
  topRated: RetrospectiveWork[]
}

export interface RetrospectivePage {
  /** Years with viewings, most recent first. Empty on a library that has never been imported. */
  availableYears: number[]
  retrospective: Retrospective | null
}

/* --------------------------------------------------------------- Le Cabinet */

export type CardSubject = 'work' | 'person' | 'studio' | 'franchise'

/** Six tiers, ordered. The French names live in utils/cards.ts with the rest of the copy. */
export type CardRarity = 'common' | 'uncommon' | 'rare' | 'super_rare' | 'ultra_rare' | 'legendary'

export type CardPackKind = 'free' | 'reel' | 'boxset'

export type CardSetFamily = 'decade' | 'genre' | 'country' | 'studio' | 'franchise'

export type CardFeatFamily = 'collection' | 'completion' | 'economy' | 'speciality'

export type CardFeatKey =
  | 'collector'
  | 'pack_rat'
  | 'first_legendary'
  | 'completionist'
  | 'full_house'
  | 'saga'
  | 'big_spender'
  | 'salvage'
  | 'auteur'
  | 'mogul'

export interface Card {
  id: Id
  subject: CardSubject
  label: string
  /** Null for every studio, and for anyone TMDB has no photo of — the face then sets type. */
  imageUrl: string | null
  /** Works only. */
  releaseYear: number | null
  /** 1 for a work; how many watched works the subject reaches, otherwise. */
  workCount: number
  /**
   * What the card *is*: frozen at the pull, or the live tier while nobody owns it. This is
   * the one the album, the showcase and every grid display.
   */
  rarity: CardRarity
  /**
   * What it would be worth in today's catalogue. Differs from `rarity` only once the library
   * has grown under an owned card — the face says so rather than hiding it.
   */
  liveRarity: CardRarity
  /** 0 to 1 within the card's own subject, 0 being the best. */
  percentile: number
  copies: number
  owned: boolean
  /** False once the subject left the library: owned, kept, no longer drawable. */
  inCatalogue: boolean
  showcasePosition: number | null
}

export interface CardListResponse {
  items: Card[]
  total: number
  page: number
  perPage: number
  /** Only on an unfiltered read — a tally of what a filter excluded is noise. */
  counts: Record<CardRarity, number> | null
}

export interface CardFacets {
  byRarity: Record<CardRarity, number>
  ownedByRarity: Record<CardRarity, number>
  bySubject: Record<CardSubject, number>
  ownedBySubject: Record<CardSubject, number>
  total: number
  owned: number
  outOfCatalogue: number
}

export interface CardDetail {
  card: Card
  score: number
  catalogueRank: number
  firstOwnedAt: string | null
  /** The two tiers disagree: the library grew and the card was re-valued. */
  revalued: boolean
}

export interface CardSet {
  family: CardSetFamily
  /** The set's identity as the grouping produced it: a decade, a name, or a UUID. */
  key: string
  label: string
  total: number
  owned: number
  rarePlus: number
  ownedRarePlus: number
  complete: boolean
  claimed: boolean
  bonus: number
  imageUrl: string | null
}

export interface CardFeat {
  key: CardFeatKey
  family: CardFeatFamily
  /** 0 means not yet won. */
  level: number
  value: number
  currentTier: number
  /** Null at the top of the ladder. */
  nextTier: number | null
}

export interface Cabinet {
  ownerDisplayName: string
  workCount: number
  cardCount: number
  ownedCount: number
  minimumWorks: number
  unlocked: boolean
  catalogueBuiltAt: string | null
  /**
   * The till. Null while viewing somebody else's profile: a collection is worth showing,
   * the money belongs to whoever is doing the looking.
   */
  balance: number | null
  lifetimeEarned: number | null
  lifetimeSpent: number | null
  packsOpened: number | null
  streakDays: number | null
  dailyGrantAvailable: boolean | null
  nextGrantAt: string | null
  freeSalvageLeft: number | null
}

export interface PackCard {
  card: Card
  isNew: boolean
  copies: number
  /** What the duplicate paid, after diminishing returns and the daily cap. 0 if new. */
  jetons: number
  /** The pack's last slot, which is the one that guarantees a floor. */
  wasGuaranteed: boolean
  /** A pity counter came due — said out loud so a pull that was owed does not read as luck. */
  wasPity: boolean
}

export interface PackResult {
  id: Id
  kind: CardPackKind
  cards: PackCard[]
  cost: number
  jetonsEarned: number
  balance: number
  /** The duplicates in this pack were worth less because the daily cap was reached. */
  dailyCapReached: boolean
  openedAt: string
}

export interface CardShowcase {
  /** Exactly six entries, gaps included — a slot left empty is not a shorter showcase. */
  slots: (Card | null)[]
  ownerDisplayName: string
}

export interface DailyGrantResult {
  granted: boolean
  amount: number
  balance: number
  streakDays: number
}
