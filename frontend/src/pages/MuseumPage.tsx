import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { fetchPosterWall } from '@/services/moviesService'
import { PosterWall } from '@/components/museum/PosterWall'
import { StarRating } from '@/components/ui/StarRating'
import { Skeleton } from '@/components/Skeleton'
import { ErrorState } from '@/components/ErrorState'
import { EmptyState } from '@/components/EmptyState'
import { SORT_OPTIONS, defaultDirectionFor } from '@/utils/movieSort'
import { cn } from '@/utils/cn'
import type { MoviePoster, MovieSortField } from '@/types/api'

export function MuseumPage() {
  const [sort, setSort] = useState<MovieSortField>('added')
  const [focused, setFocused] = useState<MoviePoster | null>(null)

  // A shuffle needs a seed that holds still, so it is drawn on the click that asks for one
  // rather than during a render — where it would reshuffle the wall under the visitor's
  // hands every time anything else changed.
  const [seed, setSeed] = useState('')
  const shuffle = () => setSeed(Math.random().toString(36).slice(2))
  const direction = defaultDirectionFor(sort)

  const { data, isLoading, isError, error } = useQuery({
    queryKey: ['movies', 'posters', sort, direction, sort === 'random' ? seed : null],
    queryFn: () => fetchPosterWall(sort, direction, sort === 'random' ? seed : undefined),
  })

  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-wrap items-end justify-between gap-x-6 gap-y-4 border-b-4 border-ink pb-6">
        <div>
          <h1 className="font-serif text-5xl font-black leading-[0.95] tracking-tighter sm:text-6xl">Le musée</h1>
          <p className="mt-2 max-w-xl font-body text-sm italic text-subtle">
            {data
              ? `${data.length} affiches accrochées au mur. Longe-le à la molette ou en le tirant ; vise une affiche pour la décrocher.`
              : 'Toutes tes affiches, accrochées au même mur.'}
          </p>
        </div>

        <div className="flex items-center gap-2">
          <label className="flex items-center gap-2">
            <span className="font-mono text-[10px] uppercase tracking-widest text-subtle">Accrochage</span>
            <select
              value={sort}
              onChange={(event) => {
                const value = event.target.value as MovieSortField
                setSort(value)
                if (value === 'random') shuffle()
              }}
              className="border border-ink bg-paper px-3 py-2 font-mono text-xs uppercase tracking-widest text-ink"
            >
              {SORT_OPTIONS.map((option) => (
                <option key={option.value} value={option.value}>
                  {option.label}
                </option>
              ))}
            </select>
          </label>

          {/* Re-picking "Aléatoire" in the select fires no change event, so the only way to
              ask for another shuffle is a button of its own. */}
          {sort === 'random' && (
            <button
              type="button"
              onClick={shuffle}
              className="border border-ink px-3 py-2 font-mono text-xs uppercase tracking-widest transition-colors hover:bg-ink hover:text-paper"
            >
              Rebattre
            </button>
          )}
        </div>
      </div>

      {isLoading && <Skeleton className="h-[62vh] w-full" />}
      {isError && <ErrorState message={(error as Error).message} />}

      {data && data.length === 0 && (
        <EmptyState
          title="Les murs sont nus"
          description="Aucun de tes films n'a encore d'affiche récupérée depuis TMDB."
        />
      )}

      {data && data.length > 0 && (
        <>
          <PosterWall posters={data} onFocus={setFocused} />
          <Cartouche poster={focused} />
        </>
      )}
    </div>
  )
}

/**
 * The label beside the picture. It keeps its place whether or not anything is under the
 * cursor, so walking the wall never makes the page jump.
 */
function Cartouche({ poster }: { poster: MoviePoster | null }) {
  return (
    <div className="flex min-h-16 flex-wrap items-center justify-between gap-x-6 gap-y-2 border-t border-ink/20 pt-4">
      <div className={cn('min-w-0 transition-opacity duration-200', poster ? 'opacity-100' : 'opacity-40')}>
        <p className="truncate font-serif text-2xl font-bold">{poster?.title ?? 'Aucune affiche visée'}</p>
        <p className="mt-0.5 font-mono text-[10px] uppercase tracking-widest text-subtle">
          {poster ? (poster.releaseYear ?? 'Année inconnue') : 'Passe le curseur sur le mur'}
        </p>
      </div>
      {poster && <StarRating rating={poster.myAverageRating} size="md" />}
    </div>
  )
}
