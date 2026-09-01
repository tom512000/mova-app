import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { Link } from 'react-router-dom'
import { Dices, RotateCcw } from 'lucide-react'
import { fetchWatchlist, fetchWatchlistFacets, pickFromWatchlist } from '@/services/watchlistService'
import type { WatchlistSearchParams } from '@/services/watchlistService'
import { MovieCard } from '@/components/MovieCard'
import { SkeletonMovieGrid } from '@/components/Skeleton'
import { ErrorState } from '@/components/ErrorState'
import { EmptyState } from '@/components/EmptyState'
import { Button, buttonVariants } from '@/components/ui/Button'
import { FilterSelect, Option } from '@/components/ui/FilterSelect'
import { formatMinutesAsDuration } from '@/utils/format'
import { scrollToTop } from '@/utils/scroll'
import { cn } from '@/utils/cn'
import type { MovieSummary, WatchlistFacets, WatchlistSortField } from '@/types/api'
import { PageMeta } from '@/components/PageMeta'

/**
 * The evening's budget, as it would be said out loud rather than as a number of minutes.
 *
 * Presets and not a slider: nobody has 97 minutes, they have "about an hour and a half", and
 * a slider would invite a precision the question does not have.
 */
const TIME_BUDGETS = [
  { value: '', label: 'Peu importe' },
  { value: '90', label: 'Moins de 1 h 30' },
  { value: '120', label: 'Moins de 2 h' },
  { value: '150', label: 'Moins de 2 h 30' },
]

const SORTS: { value: WatchlistSortField; label: string }[] = [
  { value: 'added', label: "Date d'ajout" },
  { value: 'title', label: 'Titre' },
  { value: 'year', label: 'Année de sortie' },
  { value: 'runtime', label: 'Durée' },
]

interface Filters {
  search: string
  maxRuntime: string
  genre: string
  decade: string
  sort: WatchlistSortField
  direction: 'asc' | 'desc'
}

const EMPTY_FILTERS: Filters = {
  search: '',
  maxRuntime: '',
  genre: '',
  decade: '',
  sort: 'added',
  direction: 'desc',
}

/** The query the grid and the draw both run, so they can never disagree about the shortlist. */
function toParams(filters: Filters): WatchlistSearchParams {
  return {
    q: filters.search || undefined,
    maxRuntime: filters.maxRuntime ? Number(filters.maxRuntime) : undefined,
    genre: filters.genre || undefined,
    decade: filters.decade ? Number(filters.decade) : undefined,
    sort: filters.sort,
    direction: filters.direction,
  }
}

export function WatchlistPage() {
  const [filters, setFilters] = useState<Filters>(EMPTY_FILTERS)
  const [page, setPage] = useState(1)
  const [picked, setPicked] = useState<MovieSummary | null>(null)
  const [isPicking, setIsPicking] = useState(false)

  const params = toParams(filters)

  const { data, isLoading, isError, error } = useQuery({
    queryKey: ['watchlist', params, page],
    queryFn: () => fetchWatchlist({ ...params, page, perPage: 24 }),
  })

  const facets = useQuery({ queryKey: ['watchlist', 'facets'], queryFn: fetchWatchlistFacets })

  const totalPages = data ? Math.max(1, Math.ceil(data.total / data.perPage)) : 1
  const isFiltered = JSON.stringify(filters) !== JSON.stringify(EMPTY_FILTERS)

  function turnTo(next: number) {
    setPage(next)
    scrollToTop()
  }

  function change(patch: Partial<Filters>) {
    setFilters((current) => ({ ...current, ...patch }))
    setPage(1)
    // A draw made under the old filters would be answering a question nobody is asking now.
    setPicked(null)
  }

  async function draw() {
    setIsPicking(true)
    try {
      setPicked(await pickFromWatchlist(params))
    } finally {
      setIsPicking(false)
    }
  }

  return (
    <div className="flex flex-col gap-8">
      <PageMeta title="Watchlist" />
      <div className="flex flex-col gap-4 border-b-4 border-ink pb-6 sm:flex-row sm:items-end sm:justify-between">
        <div>
          <h1 className="font-serif text-5xl font-black tracking-tighter sm:text-6xl">Watchlist</h1>
          <p className="mt-2 font-mono text-xs uppercase tracking-widest text-subtle">
            {data ? `${data.total} œuvre${data.total > 1 ? 's' : ''} à voir` : ''}
          </p>
        </div>
        <input
          value={filters.search}
          onChange={(event) => change({ search: event.target.value })}
          placeholder="Rechercher un titre..."
          className="w-full border-b-2 border-ink bg-transparent px-1 py-2 font-mono text-sm focus-visible:bg-surface focus-visible:outline-none sm:w-72"
        />
      </div>

      <FilterBar
        filters={filters}
        facets={facets.data}
        isFiltered={isFiltered}
        isPicking={isPicking}
        onChange={change}
        onDraw={() => void draw()}
        onReset={() => {
          setFilters(EMPTY_FILTERS)
          setPage(1)
          setPicked(null)
        }}
      />

      {picked !== null && <Pick movie={picked} onRedraw={() => void draw()} isPicking={isPicking} />}

      {isLoading && <SkeletonMovieGrid count={12} />}
      {isError && <ErrorState message={(error as Error).message} />}

      {data && data.items.length === 0 && (
        <EmptyState
          title={isFiltered ? 'Rien sous cette main' : 'Watchlist vide'}
          description={
            isFiltered
              ? 'Aucune œuvre de la watchlist ne tient dans ces critères. Élargis la durée ou change de genre.'
              : "Aucune œuvre ne correspond, ou ta watchlist Letterboxd n'a pas encore été importée."
          }
          action={
            isFiltered ? undefined : (
              <Link to="/import" className={cn(buttonVariants({ variant: 'primary' }), 'mt-2')}>
                Importer mes données
              </Link>
            )
          }
        />
      )}

      {data && data.items.length > 0 && (
        <>
          <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6">
            {data.items.map((movie) => (
              <MovieCard key={movie.id} movie={movie} />
            ))}
          </div>
          <div className="flex items-center justify-center gap-4">
            {/* Unlike the library, this page number lives in component state rather than
                in the URL, so paging here is not a navigation and ScrollToTop never hears
                about it. The scroll has to be asked for on the spot. */}
            <Button variant="secondary" size="sm" disabled={page <= 1} onClick={() => turnTo(page - 1)}>
              Précédent
            </Button>
            <span className="font-mono text-xs uppercase tracking-widest text-subtle">
              Page {page} / {totalPages}
            </span>
            <Button variant="secondary" size="sm" disabled={page >= totalPages} onClick={() => turnTo(page + 1)}>
              Suivant
            </Button>
          </div>
        </>
      )}
    </div>
  )
}

/**
 * The whole question the page exists to answer, in one rule of controls: how long have I got,
 * what am I in the mood for, and which era.
 *
 * "Choisis pour moi" sits in the same bar rather than above the grid because it is not a
 * different feature — it is these filters with the last step delegated.
 */
function FilterBar({
  filters,
  facets,
  isFiltered,
  isPicking,
  onChange,
  onDraw,
  onReset,
}: {
  filters: Filters
  facets?: WatchlistFacets
  isFiltered: boolean
  isPicking: boolean
  onChange: (patch: Partial<Filters>) => void
  onDraw: () => void
  onReset: () => void
}) {
  return (
    <div className="flex flex-col gap-4 border border-ink p-4">
      <div className="flex flex-col gap-4 sm:flex-row sm:flex-wrap sm:items-end sm:gap-x-6">
        <FilterSelect
          label="J'ai"
          value={filters.maxRuntime}
          onChange={(maxRuntime) => onChange({ maxRuntime })}
          className="sm:w-44"
        >
          {TIME_BUDGETS.map((budget) => (
            <Option key={budget.label} value={budget.value}>
              {budget.label}
            </Option>
          ))}
        </FilterSelect>

        <FilterSelect
          label="Genre"
          value={filters.genre}
          onChange={(genre) => onChange({ genre })}
          className="sm:w-44"
        >
          <Option value="">Tous</Option>
          {facets?.genres.map((genre) => (
            <Option key={genre} value={genre}>
              {genre}
            </Option>
          ))}
        </FilterSelect>

        <FilterSelect
          label="Décennie"
          value={filters.decade}
          onChange={(decade) => onChange({ decade })}
          className="sm:w-32"
        >
          <Option value="">Toutes</Option>
          {facets?.decades.map((decade) => (
            <Option key={decade} value={String(decade)}>
              {decade}s
            </Option>
          ))}
        </FilterSelect>

        <FilterSelect
          label="Trier par"
          value={filters.sort}
          onChange={(sort) => onChange({ sort: sort as WatchlistSortField })}
          className="sm:w-40"
        >
          {SORTS.map((sort) => (
            <Option key={sort.value} value={sort.value}>
              {sort.label}
            </Option>
          ))}
        </FilterSelect>

        <FilterSelect
          label="Ordre"
          value={filters.direction}
          onChange={(direction) => onChange({ direction: direction as 'asc' | 'desc' })}
          className="sm:w-44"
        >
          {/* Spelled out per sort: "croissant" says nothing useful about a date of addition. */}
          <Option value="desc">{filters.sort === 'added' ? 'Ajouts récents' : 'Décroissant'}</Option>
          <Option value="asc">{filters.sort === 'added' ? 'Ajouts anciens' : 'Croissant'}</Option>
        </FilterSelect>
      </div>

      <div className="flex flex-wrap items-center gap-3 border-t border-ink/15 pt-4">
        <Button variant="primary" size="sm" onClick={onDraw} disabled={isPicking}>
          <Dices className="h-4 w-4" strokeWidth={2} aria-hidden />
          {isPicking ? 'Je cherche...' : 'Choisis pour moi'}
        </Button>

        {isFiltered && (
          <Button variant="ghost" size="sm" onClick={onReset}>
            <RotateCcw className="h-3.5 w-3.5" strokeWidth={2} aria-hidden />
            Tout effacer
          </Button>
        )}

        {filters.sort === 'added' && filters.direction === 'asc' && (
          // The reason the ascending order is worth offering at all, said once rather than
          // left for the reader to work out from the dates.
          <span className="font-mono text-[10px] uppercase tracking-widest text-subtle">
            Ce qui traîne depuis le plus longtemps
          </span>
        )}
      </div>
    </div>
  )
}

/**
 * The answer to "choisis pour moi", given the room a decision deserves.
 *
 * Deliberately not a card in the grid: it is the one thing on the page that was chosen rather
 * than listed, and the runtime is spelled out next to it because the whole point was fitting
 * the evening.
 */
function Pick({ movie, onRedraw, isPicking }: { movie: MovieSummary; onRedraw: () => void; isPicking: boolean }) {
  return (
    <section className="border-4 border-ink p-5 sm:p-6">
      <p className="font-mono text-[10px] uppercase tracking-widest text-accent">Ce soir, ce sera</p>

      <div className="mt-4 flex flex-col gap-5 sm:flex-row sm:items-center">
        <Link to={`/movies/${movie.id}`} className="shrink-0 self-center sm:self-start">
          {movie.posterUrl ? (
            <img
              src={movie.posterUrl}
              alt=""
              className="w-32 border border-ink object-cover grayscale transition-[filter] hover:grayscale-0"
            />
          ) : (
            <span className="block aspect-2/3 w-32 border border-ink bg-surface-2" aria-hidden />
          )}
        </Link>

        <div className="flex flex-col items-center gap-3 text-center sm:items-start sm:text-left">
          <Link to={`/movies/${movie.id}`} className="font-serif text-3xl font-black tracking-tighter text-balance hover:text-accent sm:text-4xl">
            {movie.title}
          </Link>

          <p className="font-mono text-xs uppercase tracking-widest text-subtle">
            {movie.releaseYear ?? '—'}
            {movie.runtimeMinutes !== null && ` · ${formatMinutesAsDuration(movie.runtimeMinutes)}`}
          </p>

          <Button variant="secondary" size="sm" onClick={onRedraw} disabled={isPicking}>
            <Dices className="h-4 w-4" strokeWidth={2} aria-hidden />
            Une autre
          </Button>
        </div>
      </div>
    </section>
  )
}
