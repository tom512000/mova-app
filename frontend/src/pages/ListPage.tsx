import { useQuery } from '@tanstack/react-query'
import { useCallback, useEffect, useState } from 'react'
import { Link, useParams, useSearchParams } from 'react-router-dom'
import { ArrowDown, ArrowLeft, ArrowUp, ExternalLink, Shuffle } from 'lucide-react'
import { fetchList, fetchListEntries } from '@/services/listsService'
import { MovieCard } from '@/components/MovieCard'
import { StatCard } from '@/components/StatCard'
import { ListProgress } from '@/components/lists/ListProgress'
import { EmptyState } from '@/components/EmptyState'
import { ErrorState } from '@/components/ErrorState'
import { PageMeta } from '@/components/PageMeta'
import { Skeleton, SkeletonMovieGrid, SkeletonStatGrid } from '@/components/Skeleton'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { FilterSelect, Option } from '@/components/ui/FilterSelect'
import { useDebouncedValue } from '@/hooks/useDebouncedValue'
import { formatDate, formatMinutesAsDuration, formatRating } from '@/utils/format'
import { MEDIA_TYPE_OPTIONS } from '@/utils/movieSort'
import type { ListEntrySortField, MediaType, MovieListDetail, SortDirection } from '@/types/api'

const PER_PAGE = 24

const SORT_OPTIONS: ReadonlyArray<{ value: ListEntrySortField; label: string; defaultDirection: SortDirection }> = [
  // First and default: for a ranked list, the order *is* the list.
  { value: 'position', label: 'Ordre de la liste', defaultDirection: 'asc' },
  { value: 'title', label: 'Titre', defaultDirection: 'asc' },
  { value: 'year', label: 'Année de sortie', defaultDirection: 'asc' },
  { value: 'rating', label: 'Ma note', defaultDirection: 'desc' },
  { value: 'runtime', label: 'Durée', defaultDirection: 'desc' },
  { value: 'random', label: 'Aléatoire', defaultDirection: 'asc' },
]

const STATUS_OPTIONS = [
  { value: '', label: 'Toute la liste' },
  { value: 'unwatched', label: 'À voir' },
  { value: 'watched', label: 'Déjà vues' },
] as const

function defaultDirectionFor(sort: ListEntrySortField): SortDirection {
  return SORT_OPTIONS.find((option) => option.value === sort)?.defaultDirection ?? 'asc'
}

function newSeed(): string {
  return Math.random().toString(36).slice(2, 10)
}

export function ListPage() {
  const { id = '' } = useParams()
  // Filters live in the URL, as on the library: coming back from a film restores the view.
  const [params, setParams] = useSearchParams()

  const q = params.get('q') ?? ''
  const status = params.get('status') ?? ''
  const mediaType = params.get('mediaType') ?? ''
  const genre = params.get('genre') ?? ''
  const sort: ListEntrySortField = SORT_OPTIONS.find((option) => option.value === params.get('sort'))?.value ?? 'position'
  const direction = (params.get('direction') as SortDirection | null) ?? defaultDirectionFor(sort)
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
        // Filtering is not navigation: "back" should leave the page, not rewind the bar.
        { replace: true }
      )
    },
    [setParams]
  )

  const [searchInput, setSearchInput] = useState(q)
  const debouncedSearch = useDebouncedValue(searchInput, 300)
  useEffect(() => {
    if (debouncedSearch !== q) updateParams({ q: debouncedSearch, page: null })
  }, [debouncedSearch, q, updateParams])

  const detail = useQuery({ queryKey: ['lists', id], queryFn: () => fetchList(id), enabled: id !== '' })

  const entries = useQuery({
    queryKey: ['lists', id, 'entries', { q, status, mediaType, genre, sort, direction, seed, page }],
    queryFn: () =>
      fetchListEntries(id, {
        q: q || undefined,
        status: (status || undefined) as 'watched' | 'unwatched' | undefined,
        mediaType: (mediaType || undefined) as MediaType | undefined,
        genre: genre || undefined,
        sort,
        direction,
        seed: sort === 'random' ? seed : undefined,
        page,
        perPage: PER_PAGE,
      }),
    enabled: id !== '',
  })

  const isDirty = q !== '' || status !== '' || mediaType !== '' || genre !== '' || sort !== 'position' || direction !== 'asc'
  const totalPages = entries.data ? Math.max(1, Math.ceil(entries.data.total / entries.data.perPage)) : 1
  const list = detail.data

  return (
    <div className="flex flex-col gap-8">
      <PageMeta title={list?.name ?? 'Liste'} />

      <Link
        to="/lists"
        className="inline-flex items-center gap-1.5 self-start font-mono text-[11px] uppercase tracking-widest text-subtle transition-colors hover:text-accent"
      >
        <ArrowLeft className="h-3 w-3" strokeWidth={2} />
        Toutes les listes
      </Link>

      {detail.isLoading && <SkeletonListHeader />}
      {detail.isError && <ErrorState message={(detail.error as Error).message} />}
      {list && <ListHeader list={list} />}

      {list && list.workCount > 0 && (
        <>
          <div className="flex flex-col gap-4 border border-ink p-4">
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
              <label className="flex flex-col gap-1">
                <span className="font-mono text-[10px] uppercase tracking-widest text-subtle">Recherche</span>
                <input
                  value={searchInput}
                  onChange={(event) => setSearchInput(event.target.value)}
                  placeholder="Un titre de la liste…"
                  className="border-b-2 border-ink bg-transparent py-1 font-sans text-sm focus-visible:bg-surface focus-visible:outline-none pointer-coarse:py-3"
                />
              </label>

              <FilterSelect label="Statut" value={status} onChange={(value) => updateParams({ status: value, page: null })}>
                {STATUS_OPTIONS.map((option) => (
                  <Option key={option.value} value={option.value}>
                    {option.label}
                  </Option>
                ))}
              </FilterSelect>

              {/* Only when the list mixes both: a choice between films and nothing is no choice. */}
              {list.movieCount > 0 && list.seriesCount > 0 && (
                <FilterSelect label="Type" value={mediaType} onChange={(value) => updateParams({ mediaType: value, page: null })}>
                  {MEDIA_TYPE_OPTIONS.map((option) => (
                    <Option key={option.value} value={option.value}>
                      {option.label}
                    </Option>
                  ))}
                </FilterSelect>
              )}

              {list.genres.length > 0 && (
                <FilterSelect label="Genre" value={genre} onChange={(value) => updateParams({ genre: value, page: null })}>
                  <Option value="">Tous les genres</Option>
                  {list.genres.map((name) => (
                    <Option key={name} value={name}>
                      {name}
                    </Option>
                  ))}
                </FilterSelect>
              )}
            </div>

            <div className="flex flex-wrap items-end gap-3 border-t border-muted pt-4">
              <FilterSelect
                label="Tri"
                value={sort}
                className="min-w-48"
                onChange={(value) => {
                  const next = value as ListEntrySortField
                  updateParams({
                    sort: next === 'position' ? null : next,
                    direction: null,
                    // A shuffle needs a seed for its paging to be stable; every other sort drops it.
                    seed: next === 'random' ? newSeed() : null,
                    page: null,
                  })
                }}
              >
                {SORT_OPTIONS.map((option) => (
                  <Option key={option.value} value={option.value}>
                    {option.label}
                  </Option>
                ))}
              </FilterSelect>

              {sort === 'random' ? (
                <Button variant="secondary" size="sm" onClick={() => updateParams({ seed: newSeed(), page: null })}>
                  <Shuffle className="h-3.5 w-3.5" strokeWidth={2} />
                  Remélanger
                </Button>
              ) : (
                <Button
                  variant="secondary"
                  size="sm"
                  onClick={() => updateParams({ direction: direction === 'asc' ? 'desc' : 'asc', page: null })}
                >
                  {direction === 'asc' ? <ArrowUp className="h-3.5 w-3.5" strokeWidth={2} /> : <ArrowDown className="h-3.5 w-3.5" strokeWidth={2} />}
                  {direction === 'asc' ? 'Croissant' : 'Décroissant'}
                </Button>
              )}

              {isDirty && (
                <Button
                  variant="ghost"
                  size="sm"
                  className="ml-auto"
                  onClick={() => {
                    setSearchInput('')
                    setParams(new URLSearchParams(), { replace: true })
                  }}
                >
                  Réinitialiser
                </Button>
              )}
            </div>
          </div>

          {entries.data && (
            <p className="font-mono text-xs uppercase tracking-widest text-subtle">
              <b className="text-ink">{entries.data.total}</b> œuvre{entries.data.total > 1 ? 's' : ''}
              {status === 'unwatched' && ' à voir'}
              {status === 'watched' && ' déjà vue' + (entries.data.total > 1 ? 's' : '')}
            </p>
          )}

          {entries.isLoading && <SkeletonMovieGrid count={12} />}
          {entries.isError && <ErrorState message={(entries.error as Error).message} />}

          {entries.data && entries.data.items.length === 0 && (
            <EmptyState
              title={status === 'unwatched' && !q && !genre && !mediaType ? 'Liste terminée' : 'Rien ici'}
              description={
                status === 'unwatched' && !q && !genre && !mediaType
                  ? 'Tout ce que cette liste contient a déjà été vu.'
                  : 'Aucune œuvre de la liste ne correspond à ces critères.'
              }
            />
          )}

          {entries.data && entries.data.items.length > 0 && (
            <>
              <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6">
                {entries.data.items.map((entry) => (
                  <div key={entry.movie.id} className="flex flex-col gap-2">
                    <MovieCard movie={entry.movie} position={entry.position} markWatched />
                    {entry.note && <p className="line-clamp-3 font-body text-xs italic text-subtle">{entry.note}</p>}
                  </div>
                ))}
              </div>

              <div className="flex items-center justify-center gap-4">
                <Button variant="secondary" size="sm" disabled={page <= 1} onClick={() => updateParams({ page: String(page - 1) })}>
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
        </>
      )}

      {list && list.workCount === 0 && (
        <EmptyState title="Liste vide" description="Cette liste ne contient encore aucune œuvre sur Letterboxd." />
      )}
    </div>
  )
}

function ListHeader({ list }: { list: MovieListDetail }) {
  const remaining = list.workCount - list.watchedCount
  const kinds = [
    list.movieCount > 0 && `${list.movieCount} film${list.movieCount > 1 ? 's' : ''}`,
    list.seriesCount > 0 && `${list.seriesCount} série${list.seriesCount > 1 ? 's' : ''}`,
  ].filter(Boolean)

  return (
    <header className="flex flex-col gap-6 border-b-4 border-ink pb-8">
      <div className="flex flex-col gap-3">
        <p className="font-mono text-[10px] uppercase tracking-widest text-subtle">
          Liste
          {list.createdOn && <> &middot; créée le {formatDate(list.createdOn)}</>}
          {' '}&middot; importée le {formatDate(list.importedAt)}
        </p>
        <h1 className="text-balance font-serif text-5xl font-black leading-none tracking-tighter sm:text-6xl">{list.name}</h1>
        {/* pre-line: a Letterboxd description is written in paragraphs, and it should keep them. */}
        {list.description && <p className="max-w-2xl whitespace-pre-line font-body text-base text-subtle">{list.description}</p>}
        <div className="flex flex-wrap items-center gap-3">
          {list.tags.map((tag) => (
            <Badge key={tag}>{tag}</Badge>
          ))}
          <a
            href={list.letterboxdUrl}
            target="_blank"
            rel="noopener noreferrer"
            className="inline-flex items-center gap-1.5 font-mono text-[11px] uppercase tracking-widest underline decoration-accent decoration-2 underline-offset-4 hover:text-accent"
          >
            Voir sur Letterboxd
            <ExternalLink className="h-3 w-3" strokeWidth={2} />
          </a>
        </div>
      </div>

      <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
        <StatCard label="Œuvres" value={list.workCount} hint={kinds.join(' · ')} />
        <StatCard
          label="Déjà vues"
          value={list.watchedCount}
          hint={remaining > 0 ? `${remaining} encore à voir` : 'Liste terminée'}
        />
        <StatCard
          label="Reste à voir"
          value={list.remainingRuntimeMinutes !== null ? formatMinutesAsDuration(list.remainingRuntimeMinutes) : '—'}
          // The figure covers the films whose length TMDB knows; saying how many it leaves
          // out turns a silent undercount into a stated floor.
          hint={
            list.remainingWithoutRuntime > 0
              ? `Films seulement, + ${list.remainingWithoutRuntime} sans durée connue`
              : 'Films seulement'
          }
        />
        <StatCard label="Ma note moyenne" value={formatRating(list.averageRating)} hint="Sur les œuvres vues" />
      </div>

      <ListProgress watched={list.watchedCount} total={list.workCount} size="lg" />
    </header>
  )
}

function SkeletonListHeader() {
  return (
    <div className="flex flex-col gap-6 border-b-4 border-ink pb-8">
      <div className="flex flex-col gap-3">
        <Skeleton className="h-2.5 w-48" />
        <Skeleton className="h-12 w-3/4" />
        <Skeleton className="h-4 w-1/2" />
      </div>
      <SkeletonStatGrid count={4} />
      <Skeleton className="h-3 w-full" />
    </div>
  )
}
