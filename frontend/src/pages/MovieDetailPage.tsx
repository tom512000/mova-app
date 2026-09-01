import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link, useParams } from 'react-router-dom'
import { ArrowLeft } from 'lucide-react'
import { fetchMovie } from '@/services/moviesService'
import { Skeleton } from '@/components/Skeleton'
import { ErrorState } from '@/components/ErrorState'
import { Badge } from '@/components/ui/Badge'
import { StarRating } from '@/components/ui/StarRating'
import { formatDate, formatMinutesAsDuration } from '@/utils/format'
import type { Credit, CreditRole, MovieDetail, Watch } from '@/types/api'
import { PageMeta } from '@/components/PageMeta'

/**
 * A film has one release year; a series has a span. Collapses to a single year when the
 * whole run aired inside one — a mini-series, which most of the ones in this library are.
 */
function airedYears(movie: MovieDetail): string {
  const lastYear = movie.lastAirDate ? Number(movie.lastAirDate.slice(0, 4)) : null
  if (movie.mediaType !== 'series' || lastYear === null || lastYear === movie.releaseYear) {
    return String(movie.releaseYear)
  }
  // An en dash, not a hyphen: this is a range, not a compound word.
  return `${movie.releaseYear}–${lastYear}`
}

function episodeCountLabel(movie: MovieDetail): string | null {
  if (movie.episodeCount === null) return null

  const episodes = `${movie.episodeCount} épisode${movie.episodeCount > 1 ? 's' : ''}`
  if (movie.seasonCount === null) return episodes

  return `${movie.seasonCount} saison${movie.seasonCount > 1 ? 's' : ''} · ${episodes}`
}

export function MovieDetailPage() {
  const { id } = useParams<{ id: string }>()
  // A UUID, straight from the route — nothing to parse, and nothing that could
  // silently become NaN the way Number() did.
  const movieId = id ?? ''

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

  const isSeries = movie.mediaType === 'series'

  return (
    <div className="flex flex-col gap-8">
      <PageMeta title={movie.title} />
      <Link
        to="/movies"
        className="inline-flex w-fit items-center gap-1.5 font-mono text-xs uppercase tracking-widest text-subtle transition-colors hover:text-accent"
      >
        <ArrowLeft className="h-3 w-3" strokeWidth={1.5} /> Retour aux films
      </Link>

      {movie.backdropUrl && (
        <div className="relative -mx-4 h-64 overflow-hidden border-y-4 border-ink sm:h-80">
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
            {movie.title} {movie.releaseYear && <span className="font-normal text-subtle">({airedYears(movie)})</span>}
          </h1>
          {movie.originalTitle && movie.originalTitle !== movie.title && (
            <p className="mt-1 font-body text-sm italic text-subtle">{movie.originalTitle}</p>
          )}

          <div className="mt-4 flex flex-wrap gap-2">
            {/* Leading and solid: what kind of work this is frames everything after it. */}
            {isSeries && <Badge variant="solid">Série</Badge>}
            {isSeries && episodeCountLabel(movie) && <Badge>{episodeCountLabel(movie)}</Badge>}
            {movie.runtimeMinutes && (
              <Badge>
                {formatMinutesAsDuration(movie.runtimeMinutes)}
                {/* Spelled out, because on a series this is the whole run and "10 h 15"
                    alone would read as the length of one episode. */}
                {isSeries && ' au total'}
              </Badge>
            )}
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

          {/* Two different jobs under two different roles, so the label and the link both
              follow the credit rather than the media type. A series has no director of
              record and a film has no creator, so only one of these ever draws. */}
          {movie.directors.length > 0 && (
            <p className="mt-6 text-sm">
              <span className="font-mono text-xs uppercase tracking-widest text-subtle">Réalisé par </span>
              <CreditLinks credits={movie.directors} role="director" className="font-serif font-bold" />
            </p>
          )}

          {movie.creators.length > 0 && (
            <p className="mt-6 text-sm">
              <span className="font-mono text-xs uppercase tracking-widest text-subtle">Créé par </span>
              <CreditLinks credits={movie.creators} role="creator" className="font-serif font-bold" />
            </p>
          )}

          {movie.cast.length > 0 && (
            <p className="mt-2 text-sm">
              <span className="font-mono text-xs uppercase tracking-widest text-subtle">Avec </span>
              <CreditLinks credits={movie.cast.slice(0, 6)} role="actor" className="font-body" />
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

      <OpinionShift watches={movie.watches} />

      <ReviewSection watches={movie.watches} />

      <section className="border border-ink p-5 sm:p-6">
        <h2 className="mb-4 font-serif text-2xl font-bold">Mes visionnages ({movie.watches.length})</h2>
        <div className="flex flex-col divide-y divide-ink/15">
          {movie.watches.map((watch) => (
            <div key={watch.id} className="flex flex-col gap-2 py-4 first:pt-0 last:pb-0">
              <div className="flex items-center justify-between font-mono text-sm">
                <span className="font-semibold">{formatDate(watch.watchedDate)}</span>
                <span className="flex items-center gap-2">
                  <RewatchBadge watch={watch} />
                  <StarRating rating={watch.rating} size="md" />
                </span>
              </div>
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

/**
 * The same film, twice, and what changed in between.
 *
 * Only drawn when there is genuinely something to compare: two dated viewings that both
 * carry a rating. On a library where almost every film was seen once, that is a rare block —
 * which is the point, it means something when it appears.
 */
/**
 * Says which kind of rewatch it is rather than flattening both into one word.
 *
 * A diary entry is Letterboxd's own statement that you watched the film again. A viewing
 * worked out from a rating date moving is our reading of it, and usually right — the badge
 * carries the distinction in its title so the page does not assert more than it knows.
 */
function RewatchBadge({ watch }: { watch: Watch }) {
  if (!watch.isRewatch) return null

  return watch.isDeduced ? (
    <Badge variant="outline" title="Déduit du changement de note, non déclaré sur Letterboxd">
      Revu&nbsp;?
    </Badge>
  ) : (
    <Badge>Rewatch</Badge>
  )
}

function OpinionShift({ watches }: { watches: Watch[] }) {
  const rated = watches.filter(
    (watch): watch is Watch & { rating: number; watchedDate: string } =>
      watch.rating !== null && watch.watchedDate !== null
  )
  if (rated.length < 2) return null

  // The mapper hands the viewings back oldest first, so these are the two ends of the story.
  const first = rated[0]
  const last = rated[rated.length - 1]
  const delta = last.rating - first.rating

  const verdict =
    delta > 0
      ? 'Meilleur que dans mon souvenir'
      : delta < 0
        ? 'Moins bien que dans mon souvenir'
        : 'Exactement comme dans mon souvenir'

  return (
    <section className="border border-ink p-5 sm:p-6">
      <h2 className="font-serif text-2xl font-bold">Ce que j'en pensais avant</h2>

      <div className="mt-5 flex flex-col items-center gap-4 sm:flex-row sm:justify-center sm:gap-8">
        <Verdict label={formatDate(first.watchedDate)} rating={first.rating} />

        <span className="font-mono text-2xl text-faint" aria-hidden>
          &rarr;
        </span>

        <Verdict label={formatDate(last.watchedDate)} rating={last.rating} />
      </div>

      <p className="mt-5 text-center font-serif text-xl font-bold text-balance">
        {verdict}
        {delta !== 0 && (
          <span className="ml-2 font-mono text-sm font-normal text-subtle tabular-nums">
            {delta > 0 ? '+' : '−'}
            {Math.abs(delta).toFixed(1).replace('.', ',')} étoile{Math.abs(delta) > 1 ? 's' : ''}
          </span>
        )}
      </p>

      {rated.length > 2 && (
        <p className="mt-2 text-center font-mono text-[10px] uppercase tracking-widest text-faint">
          {rated.length} visionnages notés &middot; le premier et le dernier
        </p>
      )}

      {last.isDeduced && (
        // Said plainly rather than hidden: Letterboxd never declared this second viewing.
        // It was inferred from the rating date moving, which usually means a rewatch and
        // sometimes only means a change of heart.
        <p className="mt-4 border-t border-ink/15 pt-3 text-center font-mono text-[10px] uppercase tracking-widest text-subtle">
          Second visionnage déduit du changement de note
        </p>
      )}
    </section>
  )
}

function Verdict({ label, rating }: { label: string; rating: number }) {
  return (
    <div className="flex flex-col items-center gap-1">
      <span className="font-mono text-[10px] uppercase tracking-widest text-subtle">{label}</span>
      <StarRating rating={rating} size="lg" />
    </div>
  )
}

/**
 * What I wrote about the film, given its own block above the viewing log.
 *
 * The synopsis further up is TMDB's voice; this is mine, so it is set larger, in the body
 * face, behind a heavy rule — and it disappears entirely on the films I said nothing about
 * rather than leaving an empty section on almost every page.
 */
function ReviewSection({ watches }: { watches: Watch[] }) {
  const reviews = watches.filter((watch) => watch.reviewText)
  if (reviews.length === 0) return null

  return (
    <section className="border border-ink p-5 sm:p-6">
      <h2 className="mb-4 font-serif text-2xl font-bold">
        {reviews.length > 1 ? `Mes critiques (${reviews.length})` : 'Ma critique'}
      </h2>
      <div className="flex flex-col divide-y divide-ink/15">
        {reviews.map((watch) => (
          <article key={watch.id} className="py-5 first:pt-0 last:pb-0">
            <header className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
              <span className="flex items-center gap-2 font-mono text-xs uppercase tracking-widest text-subtle">
                {formatDate(watch.watchedDate)}
                <RewatchBadge watch={watch} />
              </span>
              <StarRating rating={watch.rating} size="md" />
            </header>
            <ReviewBody watch={watch} />
          </article>
        ))}
      </div>
    </section>
  )
}

/**
 * A review can run to several paragraphs, so the line breaks it was written with are kept.
 */
function ReviewBody({ watch }: { watch: Watch }) {
  const [revealed, setRevealed] = useState(false)

  if (watch.containsSpoilers && !revealed) {
    return (
      // A button rather than the blur-on-hover this used to be: hovering is something only
      // a mouse can do, which left the text unreachable on a phone and by keyboard.
      <div className="mt-3 flex flex-col items-center gap-2 border border-dashed border-ink/40 p-6 text-center">
        <p className="font-mono text-[10px] uppercase tracking-widest text-subtle">
          Cette critique dévoile l'intrigue
        </p>
        <button
          onClick={() => setRevealed(true)}
          className="font-mono text-xs uppercase tracking-widest text-accent underline decoration-2 underline-offset-4 hover:no-underline"
        >
          L'afficher quand même
        </button>
      </div>
    )
  }

  return (
    <blockquote className="mt-3 whitespace-pre-line border-l-4 border-ink pl-4 font-body text-base leading-relaxed text-ink/85 italic">
      {watch.reviewText}
    </blockquote>
  )
}

/**
 * Every name is a way into the library: it opens the listing filtered on that person in
 * that role. A person can hold two credits on the same film, hence the index in the key.
 */
function CreditLinks({
  credits,
  role,
  className,
}: {
  credits: Credit[]
  role: CreditRole
  className?: string
}) {
  return (
    <>
      {credits.map((credit, index) => (
        <span key={`${credit.personId}-${index}`}>
          {index > 0 && ', '}
          <Link
            to={`/movies?personId=${credit.personId}&personRole=${role}`}
            className={`underline-offset-4 decoration-accent decoration-2 hover:underline ${className ?? ''}`}
          >
            {credit.name}
          </Link>
        </span>
      ))}
    </>
  )
}
