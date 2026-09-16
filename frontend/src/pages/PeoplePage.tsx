import { useQuery } from '@tanstack/react-query'
import { useCallback, useEffect, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { fetchPeople } from '@/services/peopleService'
import { PersonCard } from '@/components/PersonCard'
import { PeopleFilters } from '@/components/PeopleFilters'
import { SkeletonPeopleGrid } from '@/components/Skeleton'
import { ErrorState } from '@/components/ErrorState'
import { EmptyState } from '@/components/EmptyState'
import { PageMeta } from '@/components/PageMeta'
import { Button } from '@/components/ui/Button'
import { useDebouncedValue } from '@/hooks/useDebouncedValue'
import { ROLE_LABEL } from '@/utils/roles'
import { defaultPersonDirectionFor, PERSON_SORT_OPTIONS, type PersonFilterState } from '@/utils/personSort'
import type { CreditRole, MediaType, PersonSortField, SortDirection } from '@/types/api'

const PER_PAGE = 24

/** Short enough to stay readable in the address bar, long enough to shuffle differently. */
function newSeed(): string {
  return Math.random().toString(36).slice(2, 10)
}

/**
 * The library read down its other axis: not what has been watched, but who.
 *
 * It exists because that question had no page. The dashboard ranks the top twenty-five of
 * four jobs and stops there — no search, no paging, nothing at all for a fifth job — and a
 * name was otherwise only reachable by already knowing which film to open. Everybody the
 * library credits now has a way in, and the same filter bar the films get.
 */
export function PeoplePage() {
  // The filters live in the URL so coming back from somebody's page restores the exact list
  // you left, and so a particular view can simply be bookmarked.
  const [params, setParams] = useSearchParams()

  const q = params.get('q') ?? ''
  const sort: PersonSortField =
    PERSON_SORT_OPTIONS.find((option) => option.value === params.get('sort'))?.value ?? 'works'
  const filters: PersonFilterState = {
    role: params.get('role') ?? '',
    mediaType: params.get('mediaType') ?? '',
    sort,
    direction: (params.get('direction') as SortDirection | null) ?? defaultPersonDirectionFor(sort),
  }
  const seed = params.get('seed') ?? ''
  const page = Math.max(1, Number(params.get('page') ?? 1))

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
        // Filtering is not navigation: browser "back" should leave the page, not rewind the
        // filter bar one control at a time.
        { replace: true }
      )
    },
    [setParams]
  )

  // The input keeps its own state so typing stays instant; the URL only catches up once the
  // typing pauses.
  const [searchInput, setSearchInput] = useState(q)
  const debouncedSearch = useDebouncedValue(searchInput, 300)
  useEffect(() => {
    if (debouncedSearch !== q) updateParams({ q: debouncedSearch, page: null })
  }, [debouncedSearch, q, updateParams])

  const { data, isLoading, isError, error } = useQuery({
    queryKey: ['people', { ...filters, q, seed, page }],
    queryFn: () =>
      fetchPeople({
        q: q || undefined,
        role: (filters.role || undefined) as CreditRole | undefined,
        mediaType: (filters.mediaType || undefined) as MediaType | undefined,
        sort: filters.sort,
        direction: filters.direction,
        seed: filters.sort === 'random' ? seed : undefined,
        page,
        perPage: PER_PAGE,
      }),
  })

  const handleFilterChange = (patch: Partial<PersonFilterState>) => {
    updateParams({ ...patch, page: null })
  }

  const handleSortChange = (nextSort: PersonSortField) => {
    updateParams({
      sort: nextSort === 'works' ? null : nextSort,
      direction: defaultPersonDirectionFor(nextSort),
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

  const hasFilters = filters.role !== '' || filters.mediaType !== '' || q !== ''
  const isDirty = hasFilters || filters.sort !== 'works' || filters.direction !== 'desc'
  const totalPages = data ? Math.max(1, Math.ceil(data.total / data.perPage)) : 1

  return (
    <div className="flex flex-col gap-6">
      <PageMeta title="Personnes" />
      <div className="flex flex-col gap-4 border-b-4 border-ink pb-6 sm:flex-row sm:items-end sm:justify-between">
        <h1 className="text-balance font-serif text-5xl font-black tracking-tighter sm:text-6xl">Personnes</h1>
        <input
          value={searchInput}
          onChange={(event) => setSearchInput(event.target.value)}
          placeholder="Rechercher un nom..."
          className="w-full border-b-2 border-ink bg-transparent px-1 py-2 font-mono text-sm focus-visible:bg-surface focus-visible:outline-none sm:w-72"
        />
      </div>

      <PeopleFilters
        state={filters}
        isDirty={isDirty}
        onChange={handleFilterChange}
        onSortChange={handleSortChange}
        onReshuffle={() => updateParams({ seed: newSeed(), page: null })}
        onReset={handleReset}
      />

      {data && (
        <p className="font-mono text-xs uppercase tracking-widest text-subtle">
          <b className="text-ink">{data.total}</b> personne{data.total > 1 ? 's' : ''}
          {activeFilterSummary(filters, q).map((label) => (
            <span key={label}> &middot; {label}</span>
          ))}
        </p>
      )}

      {isLoading && <SkeletonPeopleGrid count={12} />}
      {isError && <ErrorState message={(error as Error).message} />}

      {data && data.items.length === 0 && (
        <EmptyState
          title="Aucune personne trouvée"
          description={
            isDirty
              ? 'Personne ne correspond à ces critères. Élargis ou réinitialise les filtres.'
              : 'Importe tes données Letterboxd pour remplir cette page.'
          }
        />
      )}

      {data && data.items.length > 0 && (
        <>
          <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6">
            {data.items.map((person) => (
              <PersonCard key={person.id} person={person} />
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
function activeFilterSummary(filters: PersonFilterState, q: string): string[] {
  const labels: string[] = []
  if (q !== '') labels.push(`« ${q} »`)
  if (filters.role !== '') labels.push(ROLE_LABEL[filters.role as CreditRole])
  if (filters.mediaType === 'movie') labels.push('films')
  else if (filters.mediaType === 'series') labels.push('séries')
  return labels
}
