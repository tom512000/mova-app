import { useQuery } from '@tanstack/react-query'
import { useRef, useState, type KeyboardEvent } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import {
  fetchActivityStats,
  fetchActorStats,
  fetchCountryStats,
  fetchDecadeStats,
  fetchCreatorStats,
  fetchProducerStats,
  fetchDirectorStats,
  fetchGenreStats,
  fetchOverviewStats,
  fetchRatingStats,
  fetchReleaseWindowStats,
  fetchStudioStats,
  fetchTimelineStats,
  fetchWriterStats,
} from '@/services/statsService'
import type { CreditRole, PersonStat, ReleaseWindowStats } from '@/types/api'
import { StatCard } from '@/components/StatCard'
import {
  SkeletonChart,
  SkeletonDonut,
  SkeletonHeatmap,
  SkeletonPageHeader,
  SkeletonPersonGrid,
  SkeletonReleaseWindow,
  SkeletonStatGrid,
} from '@/components/Skeleton'
import { ErrorState } from '@/components/ErrorState'
import { EmptyState } from '@/components/EmptyState'
import { TimelineChart } from '@/charts/TimelineChart'
import { RatingDistributionChart } from '@/charts/RatingDistributionChart'
import { GenreBarChart } from '@/charts/GenreBarChart'
import { CountryDonutChart } from '@/charts/CountryDonutChart'
import { WeekdayChart } from '@/charts/WeekdayChart'
import { ActivityHeatmap } from '@/charts/ActivityHeatmap'
import { DecadeChart } from '@/charts/DecadeChart'
import { buttonVariants } from '@/components/ui/Button'
import { formatMinutesAsDays, formatMinutesAsDuration, formatRating } from '@/utils/format'
import { cn } from '@/utils/cn'
import { PageMeta } from '@/components/PageMeta'
import { useDetailedStats } from '@/hooks/useDetailedStats'

export function DashboardPage() {
  const [granularity, setGranularity] = useState<'month' | 'year'>('year')
  const { detailed, toggleDetailed } = useDetailedStats()
  const navigate = useNavigate()

  const overview = useQuery({ queryKey: ['stats', 'overview'], queryFn: fetchOverviewStats })
  const timeline = useQuery({ queryKey: ['stats', 'timeline', granularity], queryFn: () => fetchTimelineStats(granularity) })
  const ratings = useQuery({ queryKey: ['stats', 'ratings'], queryFn: fetchRatingStats })
  const genres = useQuery({ queryKey: ['stats', 'genres'], queryFn: fetchGenreStats })
  // Every country, not a top twelve: the donut groups the tail into "Autres", and that
  // wedge is only honest if it really covers everything else.
  const countries = useQuery({ queryKey: ['stats', 'countries'], queryFn: () => fetchCountryStats(100) })
  // Both are skipped entirely while the detailed blocks are hidden. Fetching them would
  // cost two requests to render nothing, and react-query keeps the answers once the
  // reader turns them back on.
  const decades = useQuery({ queryKey: ['stats', 'decades'], queryFn: fetchDecadeStats, enabled: detailed })
  const activity = useQuery({ queryKey: ['stats', 'activity'], queryFn: fetchActivityStats, enabled: detailed })
  const atRelease = useQuery({ queryKey: ['stats', 'at-release'], queryFn: fetchReleaseWindowStats })

  // The whole page, not just the cards: this branch replaces the masthead too, and a
  // grid of small boxes floating where a 60-pixel title belongs reads as a broken page.
  if (overview.isLoading)
    return (
      <div className="flex flex-col gap-10">
        <SkeletonPageHeader />
        <SkeletonStatGrid count={8} />
        <div className="border border-ink p-5 sm:p-6">
          <SkeletonChart />
        </div>
      </div>
    )
  if (overview.isError) return <ErrorState message={(overview.error as Error).message} />

  const stats = overview.data!

  if (stats.totalMovies === 0) {
    return (
      <EmptyState
        title="Aucune donnée pour l'instant"
        description="Importe ton export Letterboxd pour voir apparaître ton dashboard."
        action={
          <Link to="/import" className={cn(buttonVariants({ variant: 'primary' }), 'mt-2')}>
            Importer mes données
          </Link>
        }
      />
    )
  }

  return (
    <div className="flex flex-col gap-10">
      <PageMeta title="Dashboard" />
      <div className="flex flex-wrap items-end justify-between gap-4 border-b-4 border-ink pb-6">
        <div>
          <h1 className="font-serif text-5xl font-black leading-[0.95] tracking-tighter sm:text-6xl">Dashboard</h1>
          <p className="mt-2 font-body text-sm italic text-subtle">Vue d'ensemble de ton activité cinéphile.</p>
        </div>
        {/* aria-pressed rather than a checkbox: it is one control with an on and an off
            state, and that is exactly what a screen reader announces for a toggle button. */}
        <button
          type="button"
          onClick={toggleDetailed}
          aria-pressed={detailed}
          className={cn(
            'border border-ink px-4 py-2 font-mono text-[11px] uppercase tracking-widest transition-colors duration-200',
            detailed ? 'bg-ink text-paper' : 'text-ink hover:bg-surface'
          )}
        >
          Vue détaillée
        </button>
      </div>

      <section className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
        <Link to="/movies" className="block">
          <StatCard
            label="Films & séries"
            value={stats.totalMovies}
          />
        </Link>
        <Link to="/watchlist" className="block">
          <StatCard label="Watchlist" value={stats.totalWatchlist} />
        </Link>
        <StatCard label="Note moyenne" value={formatRating(stats.averageRating)} />
        <StatCard label="Note médiane" value={formatRating(stats.medianRating)} />
        <StatCard
          label="Temps total"
          value={formatMinutesAsDuration(stats.totalWatchTimeMinutes)}
          hint={formatMinutesAsDays(stats.totalWatchTimeMinutes)}
        />
        <StatCard
          label="Durée moyenne d'un film"
          value={stats.averageMovieRuntimeMinutes ? formatMinutesAsDuration(Math.round(stats.averageMovieRuntimeMinutes)) : '—'}
          hint={stats.averageMovieRuntimeMinutes ? `${Math.round(stats.averageMovieRuntimeMinutes)} minutes` : '—'}
        />
        <StatCard
          label="Film le plus long"
          value={stats.longestMovie ? formatMinutesAsDuration(stats.longestMovie.runtimeMinutes) : '—'}
          hint={stats.longestMovie?.title}
        />
        <StatCard
          label="Film le plus court"
          value={stats.shortestMovie ? formatMinutesAsDuration(stats.shortestMovie.runtimeMinutes) : '—'}
          hint={stats.shortestMovie?.title}
        />
      </section>

      <section className="newsprint-texture border border-ink p-5 sm:p-6">
        <div className="mb-1 flex flex-wrap items-center justify-between gap-3">
          <h2 className="font-serif text-2xl font-bold">Vus au fil du temps</h2>
          <div className="flex border border-ink">
            {(['year', 'month'] as const).map((g) => (
              <button
                key={g}
                onClick={() => setGranularity(g)}
                className={cn(
                  'px-4 py-2 font-mono text-[11px] uppercase tracking-widest transition-colors duration-200',
                  granularity === g ? 'bg-ink text-paper' : 'text-ink hover:bg-surface'
                )}
              >
                {g === 'year' ? 'Par année' : 'Par mois'}
              </button>
            ))}
          </div>
        </div>
        <p className="mb-4 text-xs text-subtle">
          Basé sur la date de journal quand elle existe, sinon la date d'ajout de la note/du visionnage sur Letterboxd.
        </p>
        {timeline.isLoading && <SkeletonChart height={280} />}
        {timeline.data && <TimelineChart data={timeline.data} />}
      </section>

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <section className="newsprint-texture border border-ink p-5 sm:p-6">
          <h2 className="mb-1 font-serif text-2xl font-bold">Distribution des notes</h2>
          {ratings.data && (
            <p className="mb-4 font-mono text-xs text-subtle">
              Moyenne {formatRating(ratings.data.average)} &middot; Médiane {formatRating(ratings.data.median)} &middot; Écart-type{' '}
              {ratings.data.standardDeviation?.toFixed(2) ?? '—'}
            </p>
          )}
          {ratings.isLoading && <SkeletonChart height={260} bars={10} />}
          {ratings.data && (
            <>
              <RatingDistributionChart
                data={ratings.data.distribution}
                onSelect={(rating) => navigate(`/movies?rating=${rating}`)}
              />
              <ChartHint />
            </>
          )}
        </section>

        <section className="newsprint-texture border border-ink p-5 sm:p-6">
          <h2 className="mb-1 font-serif text-2xl font-bold">Genres les plus regardés</h2>
          {genres.data && genres.data.length > 0 && (
            <p className="mb-4 font-mono text-xs text-subtle">
              Ton genre dominant :{' '}
              <b className="text-ink">
                {[...genres.data].sort((a, b) => b.totalWatchTimeMinutes - a.totalWatchTimeMinutes)[0].genreName}
              </b>{' '}
              &middot;{' '}
              {formatMinutesAsDuration(
                [...genres.data].sort((a, b) => b.totalWatchTimeMinutes - a.totalWatchTimeMinutes)[0].totalWatchTimeMinutes
              )}{' '}
              cumulées
            </p>
          )}
          {genres.isLoading && <SkeletonChart height={320} bars={10} />}
          {genres.data && genres.data.length > 0 ? (
            <>
              <GenreBarChart
                data={genres.data}
                onSelect={(genre) => navigate(`/movies?genre=${encodeURIComponent(genre)}`)}
              />
              <ChartHint />
            </>
          ) : (
            genres.data && <p className="text-sm text-subtle">Pas encore de genres enrichis via TMDB.</p>
          )}
        </section>
      </div>

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <section className="border border-ink p-5 sm:p-6">
          <h2 className="mb-1 font-serif text-2xl font-bold">Pays de production</h2>
          <p className="mb-4 font-mono text-xs text-subtle">
            Une coproduction compte pour chaque pays : les parts portent sur les crédits, pas sur les films
          </p>
          {countries.isLoading && <SkeletonDonut />}
          {countries.data && <CountryDonutChart data={countries.data} />}
        </section>

        <section className="border border-ink p-5 sm:p-6">
          <h2 className="mb-1 font-serif text-2xl font-bold">Vus à leur sortie</h2>
          <p className="mb-4 font-mono text-xs text-subtle">
            Découverts dans le mois suivant leur sortie
          </p>
          {atRelease.isLoading && <SkeletonReleaseWindow />}
          {atRelease.data && <ReleaseWindowPanel stats={atRelease.data} />}
        </section>
      </div>

      {detailed && (
        <>
          <section className="newsprint-texture border border-ink p-5 sm:p-6">
            <h2 className="mb-1 font-serif text-2xl font-bold">Décennies</h2>
            <p className="mb-4 font-mono text-xs text-subtle">
              Barre : films sortis dans la décennie · chiffre au-dessus : ta note moyenne
            </p>
            {decades.isLoading && <SkeletonChart height={300} />}
            {decades.isError && <ErrorState message={(decades.error as Error).message} />}
            {decades.data &&
              (decades.data.length > 0 ? (
                <>
                  <DecadeChart data={decades.data} />
                  {/* Said out loud because the chart cannot say it: a decade represented by a
                      handful of films has an average that moves a full star on one viewing. */}
                  <p className="mt-3 border-t border-ink/20 pt-3 text-xs text-subtle">
                    Une décennie représentée par quelques films a une moyenne fragile — la hauteur de
                    la barre dit combien de films portent le chiffre au-dessus d'elle.
                  </p>
                </>
              ) : (
                <p className="text-sm text-subtle">Pas encore d'année de sortie dans la bibliothèque.</p>
              ))}
          </section>

          <section className="border border-ink p-5 sm:p-6">
            <h2 className="font-serif text-2xl font-bold">Rythme</h2>
            {activity.data && (
              <p className="mt-1 flex flex-wrap gap-x-6 gap-y-1 font-mono text-xs text-subtle">
                <span>
                  <b className="text-ink">{activity.data.activeDays}</b> jours actifs sur {activity.data.spanDays}
                </span>
                <span>
                  Plus longue série <b className="text-ink">{activity.data.longestStreakDays}</b> jours
                </span>
                <span>
                  Record <b className="text-ink">{activity.data.busiestDayCount}</b> films en un jour
                </span>
              </p>
            )}
            {activity.isLoading && <SkeletonHeatmap />}
            {activity.data && (
              <div className="mt-5 flex flex-col gap-8">
                <ActivityHeatmap data={activity.data.calendar} />
                <div>
                  <h3 className="mb-2 font-mono text-[10px] uppercase tracking-widest text-subtle">Jours de la semaine</h3>
                  <WeekdayChart data={activity.data.weekdays} />
                </div>
              </div>
            )}
          </section>
        </>
      )}

      <Rankings />
    </div>
  )
}

/** Bars are a shortcut into the listing, but nothing says so until it is written down. */
function ChartHint() {
  return (
    <p className="mt-3 font-mono text-[10px] uppercase tracking-widest text-subtle">
      Clique une barre pour filtrer les films et séries
    </p>
  )
}

/**
 * The films caught while they were still new, closest to release first.
 *
 * The denominator is stated rather than implied: a film TMDB has no release date for can
 * never qualify, and quietly counting it against the total would understate the tally.
 */
function ReleaseWindowPanel({ stats }: { stats: ReleaseWindowStats }) {
  if (stats.count === 0) {
    return (
      <p className="py-12 text-center font-body text-sm text-subtle">
        Aucun film vu dans le mois de sa sortie.
      </p>
    )
  }

  return (
    <div className="flex flex-col gap-4">
      {/*
        Centred, not baseline-aligned: a baseline pins the number to the *first* line, which
        leaves a two-line block hanging well below it. And the two lines are siblings in a
        column rather than one paragraph split by a <br>, so they cannot drift apart.
      */}
      <div className="flex flex-wrap items-center gap-x-5 gap-y-2">
        <p className="font-mono text-5xl font-semibold leading-none tabular-nums">{stats.count}</p>
        <div className="flex flex-col gap-1 font-mono text-xs uppercase leading-tight tracking-widest text-subtle">
          <span>sur {stats.comparable} films datés</span>
          {stats.firstWeek > 0 && (
            <span>
              dont <b className="text-ink">{stats.firstWeek}</b> dans la première semaine
            </span>
          )}
        </div>
      </div>

      <ol className="flex max-h-72 flex-col divide-y divide-ink/15 overflow-y-auto border-t border-ink/20">
        {stats.movies.map((movie) => (
          <li key={movie.movieId} className="flex items-baseline gap-3 py-2.5">
            <span className="w-14 shrink-0 font-mono text-[10px] uppercase tracking-widest text-subtle tabular-nums">
              {movie.daysAfterRelease === 0 ? 'Jour J' : `J+${movie.daysAfterRelease}`}
            </span>
            <Link
              to={`/movies/${movie.movieId}`}
              className="min-w-0 flex-1 truncate font-serif text-base font-bold hover:text-accent"
            >
              {movie.title}
            </Link>
            <span className="shrink-0 font-mono text-xs text-subtle tabular-nums">{movie.releaseYear ?? '—'}</span>
          </li>
        ))}
      </ol>
    </div>
  )
}

/**
 * The six rankings, in one block rather than six stacked ones.
 *
 * Six sections one after another was a lot of page for six variations on the same list, and
 * nothing but the heading distinguished them. Folded together, the choice becomes the
 * interesting part and the page gets five sections shorter.
 *
 * The selector names what is being ranked rather than repeating the heading — "Réalisation",
 * not "Réalisateur·rice·s les plus vu·e·s". Inclusive plurals side by side are unreadable at
 * this size, and the heading right above already says who is being counted.
 *
 * Only the visible ranking is fetched. Six queries would otherwise fire on every dashboard
 * load to fill six blocks; one does, and react-query keeps the others once they have been
 * asked for, so coming back to a tab is instant.
 */
interface RankedItem {
  id: string
  name: string
  movieCount: number
  averageRating: number | null
}

interface Ranking {
  id: string
  /** What is ranked, not the heading: an inclusive plural does not fit a segment. */
  tab: string
  title: string
  fetch: (limit: number) => Promise<RankedItem[]>
  /** Where a card leads: the library, narrowed to exactly what that card counted. */
  href: (item: RankedItem) => string
  /** What one entry counts as. Everything here is a film except a series creator's work. */
  unit?: 'film' | 'série'
  /** Says what an entry had to be to land here, where the title alone would overpromise. */
  note?: string
  empty: string
}

/**
 * People and studios are counted by different tables and come back in different shapes;
 * flattening both to one row type here keeps that difference out of the rendering, which
 * only ever needs a name, a count and a score.
 */
function fromPeople(rows: PersonStat[]): RankedItem[] {
  return rows.map((person) => ({
    id: person.personId,
    name: person.name,
    movieCount: person.movieCount,
    averageRating: person.averageRating,
  }))
}

function personHref(role: CreditRole): (item: RankedItem) => string {
  return (item) => `/movies?personId=${item.id}&personRole=${role}`
}

// Typed rather than `as const satisfies`: the latter narrows every entry to its own literal
// shape, so `note` and `unit` vanish from the union for the entries that omit them.
const RANKINGS: readonly Ranking[] = [
  {
    id: 'director',
    tab: 'Réalisation',
    title: 'Réalisateur·rice·s les plus vu·e·s',
    fetch: (limit) => fetchDirectorStats(limit).then(fromPeople),
    href: personHref('director'),
    empty: 'Pas encore de réalisateur·rice·s enrichi·e·s via TMDB.',
  },
  {
    id: 'actor',
    tab: 'Interprétation',
    title: 'Acteur·rice·s les plus vu·e·s',
    fetch: (limit) => fetchActorStats(limit).then(fromPeople),
    href: personHref('actor'),
    empty: "Pas encore d'acteur·rice·s enrichi·e·s via TMDB.",
  },
  {
    id: 'writer',
    tab: 'Scénario',
    title: 'Scénaristes les plus vu·e·s',
    fetch: (limit) => fetchWriterStats(limit).then(fromPeople),
    href: personHref('writer'),
    empty: 'Pas encore de scénaristes enrichi·e·s via TMDB.',
  },
  {
    id: 'creator',
    tab: 'Création',
    title: 'Créateur·rice·s de séries les plus vu·e·s',
    fetch: (limit) => fetchCreatorStats(limit).then(fromPeople),
    href: personHref('creator'),
    // "séries" and not "films": counting a series as a film is the exact mislabelling the
    // creator role exists to undo.
    unit: 'série',
    empty: "Aucune série dans la bibliothèque pour l'instant.",
  },
  {
    id: 'producer',
    tab: 'Production',
    title: 'Producteur·rice·s les plus vu·e·s',
    fetch: (limit) => fetchProducerStats(limit).then(fromPeople),
    href: personHref('producer'),
    // Only the plain "Producer" credit counts, never executive producer - that one is often
    // a financing arrangement rather than a job, and counting it would fill this list with
    // studio executives. Said here because the block gives no other clue.
    note: 'Producteur·rice·s crédité·e·s comme tel·le·s, hors production exécutive',
    empty: 'Pas encore de producteur·rice·s enrichi·e·s via TMDB.',
  },
  {
    id: 'studio',
    tab: 'Studios',
    title: 'Studios les plus vus',
    fetch: (limit) =>
      fetchStudioStats(limit).then((rows) =>
        rows.map((studio) => ({
          id: studio.studioId,
          name: studio.name,
          movieCount: studio.movieCount,
          averageRating: studio.averageRating,
        }))
      ),
    href: (item) => `/movies?studioId=${item.id}`,
    // The one ranking whose rule genuinely surprises people. TMDB lists production
    // companies flat, with no lead and no role, so every one of them counts — which puts
    // broadcasters and financing arms level with the studios that actually shot the film.
    // On a French library that is enough to send TF1 Films Production to the top.
    note: 'Un film compte pour chacun de ses studios : chaînes et sociétés de financement y figurent au même titre que les studios de production',
    empty: 'Pas encore de studios enrichis via TMDB.',
  },
]

/** Three rows of three. A block of its own can carry more than a stacked one could. */
const ENTRIES_SHOWN = 9

function Rankings() {
  const [active, setActive] = useState<string>('director')
  const listRef = useRef<HTMLDivElement>(null)

  const ranking = RANKINGS.find((entry) => entry.id === active) ?? RANKINGS[0]
  const { data, isLoading, isError, error } = useQuery({
    queryKey: ['stats', 'ranking', active],
    queryFn: () => ranking.fetch(ENTRIES_SHOWN),
  })

  const move = (event: KeyboardEvent<HTMLButtonElement>) => {
    const index = RANKINGS.findIndex((entry) => entry.id === active)
    let target: number
    switch (event.key) {
      case 'ArrowRight':
        target = index + 1
        break
      case 'ArrowLeft':
        target = index - 1
        break
      case 'Home':
        target = 0
        break
      case 'End':
        target = RANKINGS.length - 1
        break
      default:
        return
    }

    event.preventDefault()
    // Wraps around: a short strip of segments reads as a loop, and one that simply stops at
    // the end feels broken rather than bounded.
    const next = RANKINGS[(target + RANKINGS.length) % RANKINGS.length]
    setActive(next.id)
    listRef.current?.querySelector<HTMLButtonElement>(`[data-ranking="${next.id}"]`)?.focus()
  }

  return (
    <section className="border border-ink p-5 sm:p-6">
      <h2 className={cn('font-serif text-2xl font-bold', undefined === ranking.note && 'mb-4')}>
        {ranking.title}
      </h2>
      {ranking.note && <p className="mb-4 mt-1 font-mono text-xs text-subtle">{ranking.note}</p>}

      {/* Scrolls rather than wraps: six segments do not fit a phone, and a second row of
          them would read as two controls instead of one. The focus ring is drawn inside each
          segment for the same reason today's square is on the heatmap — anything outside the
          box is what this scroll container clips off the first and last of them. */}
      <div className="overflow-x-auto">
        <div
          ref={listRef}
          role="tablist"
          aria-label="Classement affiché"
          className="inline-flex divide-x divide-ink border border-ink"
        >
          {RANKINGS.map((entry) => {
            const selected = entry.id === active

            return (
              <button
                key={entry.id}
                type="button"
                role="tab"
                id={`ranking-tab-${entry.id}`}
                data-ranking={entry.id}
                aria-selected={selected}
                aria-controls="ranking-panel"
                // One tab stop for the whole strip, arrows move inside it - the pattern a
                // screen reader announces and therefore the one it expects.
                tabIndex={selected ? 0 : -1}
                onClick={() => setActive(entry.id)}
                onKeyDown={move}
                className={cn(
                  'whitespace-nowrap px-3 py-2 font-mono text-[10px] uppercase tracking-widest transition-colors',
                  'focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-accent',
                  selected ? 'bg-ink text-paper' : 'hover:bg-ink/10'
                )}
              >
                {entry.tab}
              </button>
            )
          })}
        </div>
      </div>

      <div
        id="ranking-panel"
        role="tabpanel"
        aria-labelledby={`ranking-tab-${active}`}
        tabIndex={-1}
        className="mt-5"
      >
        {isLoading && <SkeletonPersonGrid count={ENTRIES_SHOWN} />}
        {isError && <ErrorState message={(error as Error).message} />}
        {data &&
          (data.length > 0 ? (
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
              {data.map((item) => (
                // Each card is the entry point to that name's films in the library.
                <Link
                  key={item.id}
                  to={ranking.href(item)}
                  className="hard-shadow-hover group block border border-ink/30 p-4"
                >
                  <p className="font-serif text-lg font-bold group-hover:text-accent">{item.name}</p>
                  <p className="mt-0.5 font-mono text-xs text-subtle">
                    {item.movieCount} {ranking.unit ?? 'film'}
                    {item.movieCount > 1 ? 's' : ''} &middot; {formatRating(item.averageRating)} moyenne
                  </p>
                </Link>
              ))}
            </div>
          ) : (
            <p className="text-sm text-subtle">{ranking.empty}</p>
          ))}
      </div>
    </section>
  )
}
