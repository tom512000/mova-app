import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { Link } from 'react-router-dom'
import { fetchDirectorStats, fetchGenreStats, fetchOverviewStats, fetchRatingStats, fetchTimelineStats } from '@/services/statsService'
import { StatCard } from '@/components/StatCard'
import { SkeletonGrid } from '@/components/Skeleton'
import { ErrorState } from '@/components/ErrorState'
import { EmptyState } from '@/components/EmptyState'
import { TimelineChart } from '@/charts/TimelineChart'
import { RatingDistributionChart } from '@/charts/RatingDistributionChart'
import { GenreBarChart } from '@/charts/GenreBarChart'
import { formatMinutesAsDays, formatMinutesAsDuration, formatRating } from '@/utils/format'

export function DashboardPage() {
  const [granularity, setGranularity] = useState<'month' | 'year'>('year')

  const overview = useQuery({ queryKey: ['stats', 'overview'], queryFn: fetchOverviewStats })
  const timeline = useQuery({ queryKey: ['stats', 'timeline', granularity], queryFn: () => fetchTimelineStats(granularity) })
  const ratings = useQuery({ queryKey: ['stats', 'ratings'], queryFn: fetchRatingStats })
  const genres = useQuery({ queryKey: ['stats', 'genres'], queryFn: fetchGenreStats })
  const directors = useQuery({ queryKey: ['stats', 'directors'], queryFn: () => fetchDirectorStats(6) })

  if (overview.isLoading) return <SkeletonGrid count={8} />
  if (overview.isError) return <ErrorState message={(overview.error as Error).message} />

  const stats = overview.data!

  if (stats.totalMovies === 0) {
    return (
      <EmptyState
        title="Aucune donnée pour l'instant"
        description="Importe ton export Letterboxd pour voir apparaître ton dashboard."
        action={
          <Link to="/import" className="mt-2 rounded-lg bg-neutral-900 px-4 py-2 text-sm font-medium text-white dark:bg-neutral-100 dark:text-neutral-900">
            Importer mes données
          </Link>
        }
      />
    )
  }

  return (
    <div className="flex flex-col gap-8">
      <div>
        <h1 className="text-2xl font-semibold tracking-tight">Dashboard</h1>
        <p className="text-sm text-neutral-500 dark:text-neutral-400">Vue d'ensemble de ton activité cinéphile.</p>
      </div>

      <section className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
        <StatCard
          label="Films vus"
          value={stats.totalMovies}
          hint={stats.totalRewatches > 0 ? `${stats.totalWatches} visionnages · ${stats.totalRewatches} rewatch${stats.totalRewatches > 1 ? 'es' : ''}` : undefined}
        />
        <Link to="/watchlist" className="transition-opacity hover:opacity-80">
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
          label="Durée moyenne"
          value={stats.averageMovieRuntimeMinutes ? formatMinutesAsDuration(Math.round(stats.averageMovieRuntimeMinutes)) : '—'}
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

      <section className="rounded-xl border border-neutral-200 bg-white p-5 dark:border-neutral-800 dark:bg-neutral-900">
        <div className="mb-1 flex items-center justify-between">
          <h2 className="text-lg font-medium">Films vus au fil du temps</h2>
          <div className="flex gap-1 rounded-lg bg-neutral-100 p-1 dark:bg-neutral-800">
            {(['year', 'month'] as const).map((g) => (
              <button
                key={g}
                onClick={() => setGranularity(g)}
                className={`rounded-md px-3 py-1 text-xs font-medium ${
                  granularity === g ? 'bg-white shadow-sm dark:bg-neutral-700' : 'text-neutral-500 dark:text-neutral-400'
                }`}
              >
                {g === 'year' ? 'Par année' : 'Par mois'}
              </button>
            ))}
          </div>
        </div>
        <p className="mb-3 text-xs text-neutral-400 dark:text-neutral-500">
          Basé sur la date de journal quand elle existe, sinon la date d'ajout de la note/du visionnage sur Letterboxd.
        </p>
        {timeline.isLoading && <SkeletonGrid count={1} />}
        {timeline.data && <TimelineChart data={timeline.data} />}
      </section>

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <section className="rounded-xl border border-neutral-200 bg-white p-5 dark:border-neutral-800 dark:bg-neutral-900">
          <h2 className="mb-1 text-lg font-medium">Distribution des notes</h2>
          {ratings.data && (
            <p className="mb-3 text-xs text-neutral-500 dark:text-neutral-400">
              Moyenne {formatRating(ratings.data.average)} · Médiane {formatRating(ratings.data.median)} · Écart-type{' '}
              {ratings.data.standardDeviation?.toFixed(2) ?? '—'}
            </p>
          )}
          {ratings.isLoading && <SkeletonGrid count={1} />}
          {ratings.data && <RatingDistributionChart data={ratings.data.distribution} />}
        </section>

        <section className="rounded-xl border border-neutral-200 bg-white p-5 dark:border-neutral-800 dark:bg-neutral-900">
          <h2 className="mb-3 text-lg font-medium">Genres les plus regardés</h2>
          {genres.isLoading && <SkeletonGrid count={1} />}
          {genres.data && genres.data.length > 0 ? (
            <GenreBarChart data={genres.data} />
          ) : (
            genres.data && <p className="text-sm text-neutral-500">Pas encore de genres enrichis via TMDB.</p>
          )}
        </section>
      </div>

      <section className="rounded-xl border border-neutral-200 bg-white p-5 dark:border-neutral-800 dark:bg-neutral-900">
        <h2 className="mb-3 text-lg font-medium">Réalisateurs les plus vus</h2>
        {directors.isLoading && <SkeletonGrid count={3} />}
        {directors.data && directors.data.length > 0 ? (
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
            {directors.data.map((d) => (
              <div key={d.personId} className="rounded-lg border border-neutral-200 p-3 dark:border-neutral-800">
                <p className="font-medium">{d.name}</p>
                <p className="text-sm text-neutral-500 dark:text-neutral-400">
                  {d.movieCount} film{d.movieCount > 1 ? 's' : ''} · {formatRating(d.averageRating)} moyenne
                </p>
              </div>
            ))}
          </div>
        ) : (
          directors.data && <p className="text-sm text-neutral-500">Pas encore de réalisateurs enrichis via TMDB.</p>
        )}
      </section>
    </div>
  )
}
