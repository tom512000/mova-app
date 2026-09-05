import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import {
  fetchActivityStats,
  fetchActorStats,
  fetchCountryStats,
  fetchCreatorStats,
  fetchProducerStats,
  fetchDirectorStats,
  fetchGenreStats,
  fetchOverviewStats,
  fetchRatingStats,
  fetchReleaseWindowStats,
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
import { buttonVariants } from '@/components/ui/Button'
import { formatMinutesAsDays, formatMinutesAsDuration, formatRating } from '@/utils/format'
import { cn } from '@/utils/cn'
import { PageMeta } from '@/components/PageMeta'

export function DashboardPage() {
  const [granularity, setGranularity] = useState<'month' | 'year'>('year')
  const navigate = useNavigate()

  const overview = useQuery({ queryKey: ['stats', 'overview'], queryFn: fetchOverviewStats })
  const timeline = useQuery({ queryKey: ['stats', 'timeline', granularity], queryFn: () => fetchTimelineStats(granularity) })
  const ratings = useQuery({ queryKey: ['stats', 'ratings'], queryFn: fetchRatingStats })
  const genres = useQuery({ queryKey: ['stats', 'genres'], queryFn: fetchGenreStats })
  const directors = useQuery({ queryKey: ['stats', 'directors'], queryFn: () => fetchDirectorStats(6) })
  const actors = useQuery({ queryKey: ['stats', 'actors'], queryFn: () => fetchActorStats(6) })
  const writers = useQuery({ queryKey: ['stats', 'writers'], queryFn: () => fetchWriterStats(6) })
  const creators = useQuery({ queryKey: ['stats', 'creators'], queryFn: () => fetchCreatorStats(6) })
  const producers = useQuery({ queryKey: ['stats', 'producers'], queryFn: () => fetchProducerStats(6) })
  // Every country, not a top twelve: the donut groups the tail into "Autres", and that
  // wedge is only honest if it really covers everything else.
  const countries = useQuery({ queryKey: ['stats', 'countries'], queryFn: () => fetchCountryStats(100) })
  const activity = useQuery({ queryKey: ['stats', 'activity'], queryFn: fetchActivityStats })
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
      <div className="border-b-4 border-ink pb-6">
        <h1 className="font-serif text-5xl font-black leading-[0.95] tracking-tighter sm:text-6xl">Dashboard</h1>
        <p className="mt-2 font-body text-sm italic text-subtle">Vue d'ensemble de ton activité cinéphile.</p>
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

      <PersonStatSection
        role="director"
        title="Réalisateur·rice·s les plus vu·e·s"
        isLoading={directors.isLoading}
        data={directors.data}
        emptyMessage="Pas encore de réalisateur·rice·s enrichi·e·s via TMDB."
      />

      <PersonStatSection
        role="actor"
        title="Acteur·rice·s les plus vu·e·s"
        isLoading={actors.isLoading}
        data={actors.data}
        emptyMessage="Pas encore d'acteur·rice·s enrichi·e·s via TMDB."
      />

      <PersonStatSection
        role="writer"
        title="Scénaristes les plus vu·e·s"
        isLoading={writers.isLoading}
        data={writers.data}
        emptyMessage="Pas encore de scénaristes enrichi·e·s via TMDB."
      />

      <PersonStatSection
        role="creator"
        title="Créateur·rice·s de séries les plus vu·e·s"
        isLoading={creators.isLoading}
        data={creators.data}
        // "séries" and not "films": counting a series as a film is the exact mislabelling
        // the creator role exists to undo.
        unit="série"
        emptyMessage="Aucune série dans la bibliothèque pour l'instant."
      />

      <PersonStatSection
        role="producer"
        title="Producteur·rice·s les plus vu·e·s"
        isLoading={producers.isLoading}
        data={producers.data}
        // Only the plain "Producer" credit counts, never executive producer — that one is
        // often a financing arrangement rather than a job, and counting it would fill this
        // list with studio executives. Said here because the block gives no other clue.
        note="Producteur·rice·s crédité·e·s comme tel·le·s, hors production exécutive"
        emptyMessage="Pas encore de producteur·rice·s enrichi·e·s via TMDB."
      />
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

function PersonStatSection({
  role,
  title,
  isLoading,
  data,
  emptyMessage,
  note,
  unit = 'film',
}: {
  role: CreditRole
  title: string
  isLoading: boolean
  data: PersonStat[] | undefined
  emptyMessage: string
  /** Says what a credit had to be to land here, where the title alone would overpromise. */
  note?: string
  /** What one credit counts as. Everything here is a film except a series creator's work. */
  unit?: 'film' | 'série'
}) {
  return (
    <section className="border border-ink p-5 sm:p-6">
      <h2 className={cn('font-serif text-2xl font-bold', undefined === note && 'mb-4')}>{title}</h2>
      {note && <p className="mb-4 mt-1 font-mono text-xs text-subtle">{note}</p>}
      {isLoading && <SkeletonPersonGrid count={6} />}
      {data && data.length > 0 ? (
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {data.map((p) => (
            // Each card is the entry point to that person's films in the library.
            <Link
              key={p.personId}
              to={`/movies?personId=${p.personId}&personRole=${role}`}
              className="hard-shadow-hover group block border border-ink/30 p-4"
            >
              <p className="font-serif text-lg font-bold group-hover:text-accent">{p.name}</p>
              <p className="mt-0.5 font-mono text-xs text-subtle">
                {p.movieCount} {unit}
                {p.movieCount > 1 ? 's' : ''} &middot; {formatRating(p.averageRating)} moyenne
              </p>
            </Link>
          ))}
        </div>
      ) : (
        data && <p className="text-sm text-subtle">{emptyMessage}</p>
      )}
    </section>
  )
}
