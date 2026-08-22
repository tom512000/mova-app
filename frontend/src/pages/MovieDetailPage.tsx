import { useQuery } from '@tanstack/react-query'
import { Link, useParams } from 'react-router-dom'
import { ArrowLeft } from 'lucide-react'
import { fetchMovie } from '@/services/moviesService'
import { Skeleton } from '@/components/Skeleton'
import { ErrorState } from '@/components/ErrorState'
import { Badge } from '@/components/ui/Badge'
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
    <div className="flex flex-col gap-8">
      <Link
        to="/movies"
        className="inline-flex w-fit items-center gap-1.5 font-mono text-xs uppercase tracking-widest text-subtle transition-colors hover:text-accent"
      >
        <ArrowLeft className="h-3 w-3" strokeWidth={1.5} /> Retour aux films
      </Link>

      {movie.backdropUrl && (
        <div className="relative -mx-4 h-64 overflow-hidden border-y-4 border-ink sm:h-80 lg:-mx-8">
          <img src={movie.backdropUrl} alt="" className="h-full w-full object-cover grayscale" />
          <div className="absolute inset-0 bg-linear-to-t from-paper via-transparent to-transparent" />
        </div>
      )}

      <div className="grid grid-cols-1 gap-8 lg:grid-cols-12">
        <div className="lg:col-span-4">
          <div className="border border-ink bg-surface-2">
            {movie.posterUrl ? (
              <img src={movie.posterUrl} alt={movie.title} className="w-full grayscale" />
            ) : (
              <div className="flex aspect-2/3 items-center justify-center font-mono text-xs uppercase tracking-widest text-subtle">
                Pas d'affiche
              </div>
            )}
          </div>
          <p className="mt-2 font-mono text-[10px] uppercase tracking-widest text-subtle">Fig. 1 &mdash; {movie.title}</p>
        </div>

        <div className="lg:col-span-8">
          <h1 className="font-serif text-4xl font-black leading-[0.95] tracking-tight sm:text-5xl">
            {movie.title} {movie.releaseYear && <span className="font-normal text-subtle">({movie.releaseYear})</span>}
          </h1>
          {movie.originalTitle && movie.originalTitle !== movie.title && (
            <p className="mt-1 font-body text-sm italic text-subtle">{movie.originalTitle}</p>
          )}

          <div className="mt-4 flex flex-wrap gap-2">
            {movie.runtimeMinutes && <Badge>{formatMinutesAsDuration(movie.runtimeMinutes)}</Badge>}
            {movie.genres.map((g) => (
              <Badge key={g}>{g}</Badge>
            ))}
            {movie.countries.map((c) => (
              <Badge key={c}>{c}</Badge>
            ))}
          </div>

          {movie.synopsis && (
            <p className="mt-6 max-w-2xl text-justify font-body text-sm leading-relaxed text-ink/80 first-letter:float-left first-letter:pr-2 first-letter:font-serif first-letter:text-7xl first-letter:font-black first-letter:leading-[0.75] first-letter:text-ink">
              {movie.synopsis}
            </p>
          )}

          {movie.directors.length > 0 && (
            <p className="mt-6 text-sm">
              <span className="font-mono text-xs uppercase tracking-widest text-subtle">Réalisé par </span>
              <span className="font-serif font-bold">{movie.directors.map((d) => d.name).join(', ')}</span>
            </p>
          )}

          {movie.cast.length > 0 && (
            <p className="mt-2 text-sm">
              <span className="font-mono text-xs uppercase tracking-widest text-subtle">Avec </span>
              <span className="font-body">{movie.cast.slice(0, 6).map((c) => c.name).join(', ')}</span>
            </p>
          )}

          <div className="mt-6 flex flex-wrap items-center gap-5 border-t border-ink/20 pt-4 font-mono text-xs">
            {movie.tmdbVoteAverage !== null && (
              <span>
                TMDB <strong className="text-base">{movie.tmdbVoteAverage.toFixed(1)}</strong>
              </span>
            )}
            {movie.imdbId && (
              <a
                href={`https://www.imdb.com/title/${movie.imdbId}/`}
                target="_blank"
                rel="noreferrer"
                className="uppercase tracking-widest text-accent hover:underline"
              >
                IMDb &#8599;
              </a>
            )}
          </div>
        </div>
      </div>

      <section className="border border-ink p-5 sm:p-6">
        <h2 className="mb-4 font-serif text-2xl font-bold">Mes visionnages ({movie.watches.length})</h2>
        <div className="flex flex-col divide-y divide-ink/15">
          {movie.watches.map((watch) => (
            <div key={watch.id} className="flex flex-col gap-2 py-4 first:pt-0 last:pb-0">
              <div className="flex items-center justify-between font-mono text-sm">
                <span className="font-semibold">{formatDate(watch.watchedDate)}</span>
                <span className="flex items-center gap-2">
                  {watch.isRewatch && <Badge>Rewatch</Badge>}
                  <span>★ {formatRating(watch.rating)}</span>
                </span>
              </div>
              {watch.reviewText && (
                <p
                  className={
                    watch.containsSpoilers
                      ? 'font-body text-sm text-faint blur-sm transition-all duration-200 hover:blur-none'
                      : 'font-body text-sm text-ink/70'
                  }
                >
                  {watch.reviewText}
                </p>
              )}
              {watch.tags.length > 0 && (
                <div className="flex flex-wrap gap-1">
                  {watch.tags.map((tag) => (
                    <Badge key={tag} variant="outline">
                      #{tag}
                    </Badge>
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
