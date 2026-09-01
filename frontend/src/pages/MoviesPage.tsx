import { useQuery } from '@tanstack/react-query'
import { useCallback, useEffect, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { fetchMovieFacets, fetchMovies } from '@/services/moviesService'
import { MovieCard } from '@/components/MovieCard'
import { MovieFilters } from '@/components/MovieFilters'
import { SkeletonGrid } from '@/components/Skeleton'
import { ErrorState } from '@/components/ErrorState'
import { EmptyState } from '@/components/EmptyState'
import { Button } from '@/components/ui/Button'
import { useDebouncedValue } from '@/hooks/useDebouncedValue'
import { ratingToStars } from '@/utils/format'
import { SORT_OPTIONS, defaultDirectionFor, type MovieFilterState } from '@/utils/movieSort'
import type { CreditRole, MediaType, MovieSortField, SortDirection } from '@/types/api'
import { PageMeta } from '@/components/PageMeta'

const PER_PAGE = 24

/** Short enough to stay readable in the address bar, long enough to shuffle differently. */
function newSeed(): string {
  return Math.random().toString(36).slice(2, 10)
}

export function MoviesPage() {
  // The filters live in the URL so coming back from a film restores the exact list you
  // left, and so a particular view can simply be bookmarked.
  const [params, setParams] = useSearchParams()

  const q = params.get('q') ?? ''
  const sort: MovieSortField =
    SORT_OPTIONS.find((option) => option.value === params.get('sort'))?.value ?? 'title'
  const filters: MovieFilterState = {
    genre: params.get('genre') ?? '',
    mediaType: params.get('mediaType') ?? '',
    rating: params.get('rating') ?? '',
    year: params.get('year') ?? '',
    sort,
    direction: (params.get('direction') as SortDirection | null) ?? defaultDirectionFor(sort),
  }
  const seed = params.get('seed') ?? ''
  const page = Math.max(1, Number(params.get('page') ?? 1))
  // Reached by clicking a name on the dashboard: the URL carries the id, the listing
  // answers with the name to label it.
  const personId = params.get('personId') ?? ''
  const personRole = params.get('personRole') as CreditRole | null

  const updateParams = useCallback(
    (patch: Record<string, string | null | undefined>) => {
      setParams(
        (previous) => {
          const next = new URLSearchParams(previous)
          for (const [key, value] of Object.entries(patch)) {
            if (value === null || value === undefined || value === '') next.delete(key)
            else next.set(key, value)
          }
          return next
        },
        // Filtering is not navigation: browser "back" should leave the page, not rewind
        // the filter bar one control at a time.
        { replace: true }
      )
    },
    [setParams]
  )

  // The input keeps its own state so typing stays instant; the URL only catches up once
  // the typing pauses.
  const [searchInput, setSearchInput] = useState(q)
  const debouncedSearch = useDebouncedValue(searchInput, 300)
  useEffect(() => {
    if (debouncedSearch !== q) updateParams({ q: debouncedSearch, page: null })
  }, [debouncedSearch, q, updateParams])

  const facets = useQuery({ queryKey: ['movies', 'facets'], queryFn: fetchMovieFacets, staleTime: 5 * 60 * 1000 })

  const { data, isLoading, isError, error } = useQuery({
    queryKey: ['movies', { ...filters, q, seed, page, personId, personRole }],
    queryFn: () =>
      fetchMovies({
        q: q || undefined,
        genre: filters.genre || undefined,
        mediaType: (filters.mediaType || undefined) as MediaType | undefined,
        rating: filters.rating || undefined,
        year: filters.year ? Number(filters.year) : undefined,
        sort: filters.sort,
        direction: filters.direction,
        seed: filters.sort === 'random' ? seed : undefined,
        personId: personId || undefined,
        personRole: personRole ?? undefined,
        page,
        perPage: PER_PAGE,
      }),
  })

  const handleFilterChange = (patch: Partial<MovieFilterState>) => {
    updateParams({ ...patch, page: null })
  }

  const handleSortChange = (nextSort: MovieSortField) => {
    updateParams({
      sort: nextSort === 'title' ? null : nextSort,
      direction: defaultDirectionFor(nextSort),
      // A shuffle needs a seed for its paging to be stable; every other sort must drop it,
      // otherwise going back to Aléatoire would replay the same order.
      seed: nextSort === 'random' ? newSeed() : null,
      page: null,
    })
  }

  const handleReset = () => {
    setSearchInput('')
    setParams(new URLSearchParams(), { replace: true })
  }

  // Until the listing comes back the name is unknown, but the chip must already be there
  // or the bar would jump a line once it arrives.
  const activePerson = personId !== ''
    ? { name: data?.person?.name ?? '…', role: data?.person?.role ?? personRole }
    : null

  const hasFilters =
    filters.genre !== '' ||
    filters.mediaType !== '' ||
    filters.rating !== '' ||
    filters.year !== '' ||
    q !== '' ||
    personId !== ''
  const isDirty = hasFilters || filters.sort !== 'title' || filters.direction !== 'asc'
  const totalPages = data ? Math.max(1, Math.ceil(data.total / data.perPage)) : 1

  return (
    <div className="flex flex-col gap-6">
      <PageMeta title="Films et séries" />
      <div className="flex flex-col gap-4 border-b-4 border-ink pb-6 sm:flex-row sm:items-end sm:justify-between">
        <h1 className="text-balance font-serif text-5xl font-black tracking-tighter sm:text-6xl">Films et séries</h1>
        <input
          value={searchInput}
          onChange={(event) => setSearchInput(event.target.value)}
          placeholder="Rechercher un titre..."
          className="w-full border-b-2 border-ink bg-transparent px-1 py-2 font-mono text-sm focus-visible:bg-surface focus-visible:outline-none sm:w-72"
        />
      </div>

      <MovieFilters
        state={filters}
        facets={facets.data}
        isDirty={isDirty}
        onChange={handleFilterChange}
        onSortChange={handleSortChange}
        person={activePerson}
        onReshuffle={() => updateParams({ seed: newSeed(), page: null })}
        onReset={handleReset}
        onClearPerson={() => updateParams({ personId: null, personRole: null, page: null })}
      />

      {data && (
        <p className="font-mono text-xs uppercase tracking-widest text-subtle">
          <b className="text-ink">{data.total}</b> film{data.total > 1 ? 's' : ''}
          {activeFilterSummary(filters, q).map((label) => (
            <span key={label}> &middot; {label}</span>
          ))}
        </p>
      )}

      {isLoading && <SkeletonGrid count={12} />}
      {isError && <ErrorState message={(error as Error).message} />}

      {data && data.items.length === 0 && (
        <EmptyState
          title="Aucun film trouvé"
          description={
            isDirty
              ? 'Aucun film ne correspond à ces critères. Élargis ou réinitialise les filtres.'
              : 'Importe tes données Letterboxd pour remplir cette page.'
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
            <Button
              variant="secondary"
              size="sm"
              disabled={page <= 1}
              onClick={() => updateParams({ page: String(page - 1) })}
            >
              Précédent
            </Button>
            <span className="font-mono text-xs uppercase tracking-widest text-subtle">
              Page {page} / {totalPages}
            </span>
            <Button
              variant="secondary"
              size="sm"
              disabled={page >= totalPages}
              onClick={() => updateParams({ page: String(page + 1) })}
            >
              Suivant
            </Button>
          </div>
        </>
      )}
    </div>
  )
}

/** Restates what is currently narrowing the list, since the selects alone are easy to miss. */
function activeFilterSummary(filters: MovieFilterState, q: string): string[] {
  const labels: string[] = []
  if (q !== '') labels.push(`« ${q} »`)
  if (filters.genre !== '') labels.push(filters.genre)
  if (filters.rating === 'none') labels.push('non notés')
  else if (filters.rating !== '') labels.push(ratingToStars(Number(filters.rating)))
  if (filters.year !== '') labels.push(filters.year)
  return labels
}
