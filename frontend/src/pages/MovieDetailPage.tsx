import { useQuery } from '@tanstack/react-query'
import { Link, useParams } from 'react-router-dom'
import { fetchMovie } from '@/services/moviesService'
import { Skeleton } from '@/components/Skeleton'
import { ErrorState } from '@/components/ErrorState'
import { formatDate, formatMinutesAsDuration, formatRating } from '@/utils/format'

export function MovieDetailPage() {
  const { id } = useParams<{ id: string }>()
  const movieId = Number(id)

  const { data: movie, isLoading, isError, error } = useQuery({
    queryKey: ['movie', movieId],
    queryFn: () => fetchMovie(movieId),
  })

  if (isLoading) {
    return (
      <div className="flex flex-col gap-4">
        <Skeleton className="h-64 w-full" />
        <Skeleton className="h-8 w-1/2" />
        <Skeleton className="h-4 w-1/3" />
      </div>
    )
  }

  if (isError || !movie) return <ErrorState message={(error as Error)?.message} />

  return (
    <div className="flex flex-col gap-6">
      <Link to="/movies" className="text-sm text-neutral-500 hover:underline dark:text-neutral-400">
        ← Retour aux films
      </Link>

      {movie.backdropUrl && (
        <div className="relative -mx-6 -mt-2 h-56 overflow-hidden lg:-mx-8">
          <img src={movie.backdropUrl} alt="" className="h-full w-full object-cover opacity-60" />
          <div className="absolute inset-0 bg-gradient-to-t from-neutral-50 dark:from-neutral-950" />
        </div>
      )}

      <div className="flex flex-col gap-6 sm:flex-row">
        <div className="w-40 shrink-0 overflow-hidden rounded-xl bg-neutral-200 shadow-md dark:bg-neutral-800">
          {movie.posterUrl ? (
            <img src={movie.posterUrl} alt={movie.title} className="w-full" />
          ) : (
            <div className="flex aspect-[2/3] items-center justify-center text-xs text-neutral-400">Pas d'affiche</div>
          )}
        </div>

        <div className="flex-1">
          <h1 className="text-3xl font-semibold tracking-tight">
            {movie.title} {movie.releaseYear && <span className="font-normal text-neutral-400">({movie.releaseYear})</span>}
          </h1>
          {movie.originalTitle && movie.originalTitle !== movie.title && (
            <p className="text-sm italic text-neutral-500 dark:text-neutral-400">{movie.originalTitle}</p>
          )}

          <div className="mt-3 flex flex-wrap gap-2 text-xs text-neutral-500 dark:text-neutral-400">
            {movie.runtimeMinutes && <span>{formatMinutesAsDuration(movie.runtimeMinutes)}</span>}
            {movie.genres.map((g) => (
              <span key={g} className="rounded-full bg-neutral-100 px-2 py-0.5 dark:bg-neutral-800">
                {g}
              </span>
            ))}
            {movie.countries.map((c) => (
              <span key={c} className="rounded-full bg-neutral-100 px-2 py-0.5 dark:bg-neutral-800">
                {c}
              </span>
            ))}
          </div>

          {movie.synopsis && <p className="mt-4 max-w-2xl text-sm text-neutral-700 dark:text-neutral-300">{movie.synopsis}</p>}

          {movie.directors.length > 0 && (
            <p className="mt-4 text-sm">
              <span className="text-neutral-500 dark:text-neutral-400">Réalisé par </span>
              <span className="font-medium">{movie.directors.map((d) => d.name).join(', ')}</span>
            </p>
          )}

          {movie.cast.length > 0 && (
            <p className="mt-1 text-sm">
              <span className="text-neutral-500 dark:text-neutral-400">Avec </span>
              {movie.cast.slice(0, 6).map((c) => c.name).join(', ')}
            </p>
          )}

          <div className="mt-4 flex gap-4 text-sm">
            {movie.tmdbVoteAverage !== null && (
              <span>
                Note TMDB : <strong>{movie.tmdbVoteAverage.toFixed(1)}</strong>
              </span>
            )}
            {movie.imdbId && (
              <a
                href={`https://www.imdb.com/title/${movie.imdbId}/`}
                target="_blank"
                rel="noreferrer"
                className="text-neutral-500 hover:underline dark:text-neutral-400"
              >
                IMDb ↗
              </a>
            )}
          </div>
        </div>
      </div>

      <section className="rounded-xl border border-neutral-200 bg-white p-5 dark:border-neutral-800 dark:bg-neutral-900">
        <h2 className="mb-3 text-lg font-medium">
          Mes visionnages ({movie.watches.length})
        </h2>
        <div className="flex flex-col divide-y divide-neutral-100 dark:divide-neutral-800">
          {movie.watches.map((watch) => (
            <div key={watch.id} className="flex flex-col gap-1 py-3">
              <div className="flex items-center justify-between text-sm">
                <span className="font-medium">{formatDate(watch.watchedDate)}</span>
                <span className="flex items-center gap-2">
                  {watch.isRewatch && (
                    <span className="rounded-full bg-neutral-100 px-2 py-0.5 text-[10px] font-medium dark:bg-neutral-800">Rewatch</span>
                  )}
                  <span>★ {formatRating(watch.rating)}</span>
                </span>
              </div>
              {watch.reviewText && (
                <p className={watch.containsSpoilers ? 'text-sm text-neutral-400 blur-sm hover:blur-none' : 'text-sm text-neutral-600 dark:text-neutral-300'}>
                  {watch.reviewText}
                </p>
              )}
              {watch.tags.length > 0 && (
                <div className="flex flex-wrap gap-1">
                  {watch.tags.map((tag) => (
                    <span key={tag} className="rounded-full bg-neutral-100 px-2 py-0.5 text-[10px] dark:bg-neutral-800">
                      #{tag}
                    </span>
                  ))}
                </div>
              )}
            </div>
          ))}
        </div>
      </section>
    </div>
  )
}
