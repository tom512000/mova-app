import { useSearchParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import type { CardRarity, CardSubject } from '@/types/api'
import { PageMeta } from '@/components/PageMeta'
import { EmptyState } from '@/components/EmptyState'
import { ErrorState } from '@/components/ErrorState'
import { SkeletonCardGrid } from '@/components/Skeleton'
import { Button } from '@/components/ui/Button'
import { FilterSelect, Option } from '@/components/ui/FilterSelect'
import { CardGrid } from '@/components/cards/CardGrid'
import { fetchCards } from '@/services/cardsService'
import { useCabinet } from '@/hooks/useCabinet'
import { RARITY_COPY, RARITY_ORDER, SUBJECT_LABEL } from '@/utils/cards'
import { apiErrorMessage } from '@/utils/apiError'

const PER_PAGE = 60

/**
 * The album.
 *
 * Structurally the badge shelf: a filter bar over a paged grid, with the filters in the URL
 * so a view can be bookmarked and shared. The default order is the catalogue's own — best
 * shelf first, stable between visits — because an album that rearranged itself every time a
 * card was pulled would stop being a place and become a feed.
 */
export function CardAlbumPage() {
  const [params, setParams] = useSearchParams()
  const { facets } = useCabinet()

  const subject = (params.get('subject') ?? '') as CardSubject | ''
  const rarity = (params.get('rarity') ?? '') as CardRarity | ''
  const owned = params.get('owned') ?? ''
  const sort = (params.get('sort') ?? 'rank') as 'rank' | 'recent' | 'score' | 'name'
  const query = params.get('q') ?? ''
  const page = Math.max(1, Number(params.get('page') ?? 1))

  const update = (key: string, value: string) => {
    const next = new URLSearchParams(params)
    if (value === '') next.delete(key)
    else next.set(key, value)
    // Any change to what is being looked at starts again at the top.
    if (key !== 'page') next.delete('page')
    setParams(next)
  }

  const { data, isLoading, isError, error } = useQuery({
    queryKey: ['cards', 'list', { subject, rarity, owned, sort, query, page }],
    queryFn: () =>
      fetchCards({
        subject: subject === '' ? undefined : subject,
        rarity: rarity === '' ? undefined : rarity,
        owned: owned === '' ? undefined : owned === '1',
        sort,
        q: query === '' ? undefined : query,
        page,
        perPage: PER_PAGE,
      }),
    staleTime: 30_000,
  })

  const pageCount = data === undefined ? 1 : Math.max(1, Math.ceil(data.total / data.perPage))

  return (
    <div className="flex flex-col gap-8">
      <PageMeta title="L’album" />

      <header className="flex flex-col gap-3 border-b-4 border-ink pb-6">
        <h1 className="font-serif text-5xl font-black tracking-tighter sm:text-6xl">L’album</h1>
        {facets.data !== undefined && (
          <p className="font-mono text-[11px] uppercase tracking-widest tabular-nums text-subtle">
            <b className="text-ink">{facets.data.owned.toLocaleString('fr-FR')}</b> cartes sur{' '}
            {facets.data.total.toLocaleString('fr-FR')}
            {facets.data.outOfCatalogue > 0 &&
              ` · ${facets.data.outOfCatalogue} hors catalogue`}
          </p>
        )}
      </header>

      <div className="flex flex-wrap items-end gap-4">
        <FilterSelect label="Sujet" value={subject} onChange={(value) => update('subject', value)}>
          <Option value="">Tous</Option>
          {(Object.keys(SUBJECT_LABEL) as CardSubject[]).map((value) => (
            <Option key={value} value={value}>
              {SUBJECT_LABEL[value]}
            </Option>
          ))}
        </FilterSelect>

        <FilterSelect label="Rareté" value={rarity} onChange={(value) => update('rarity', value)}>
          <Option value="">Toutes</Option>
          {RARITY_ORDER.map((value) => (
            <Option key={value} value={value}>
              {RARITY_COPY[value].name}
              {facets.data !== undefined && ` (${facets.data.ownedByRarity[value] ?? 0}/${facets.data.byRarity[value] ?? 0})`}
            </Option>
          ))}
        </FilterSelect>

        <FilterSelect label="Possession" value={owned} onChange={(value) => update('owned', value)}>
          <Option value="">Tout</Option>
          <Option value="1">Ma collection</Option>
          <Option value="0">Ce qu’il me manque</Option>
        </FilterSelect>

        <FilterSelect label="Tri" value={sort} onChange={(value) => update('sort', value)}>
          <Option value="rank">Ordre du catalogue</Option>
          <Option value="recent">Trouvées récemment</Option>
          <Option value="score">Score</Option>
          <Option value="name">Nom</Option>
        </FilterSelect>

        <label className="flex flex-1 flex-col gap-1">
          <span className="font-mono text-[10px] uppercase tracking-widest text-subtle">Recherche</span>
          <input
            type="search"
            defaultValue={query}
            onChange={(event) => update('q', event.target.value)}
            placeholder="Un titre, un nom, un studio…"
            className="w-full border-b-2 border-ink bg-transparent py-2 font-sans text-sm placeholder:text-faint focus:outline-none"
          />
        </label>
      </div>

      {isLoading && <SkeletonCardGrid />}
      {isError && <ErrorState message={apiErrorMessage(error, 'L’album n’a pas pu être chargé.')} />}

      {data !== undefined && data.items.length === 0 && (
        <EmptyState
          title="Rien de ce côté-là"
          description="Aucune carte ne correspond à ces filtres."
        />
      )}

      {data !== undefined && data.items.length > 0 && (
        <>
          <CardGrid cards={data.items} />

          {pageCount > 1 && (
            <nav className="flex items-center justify-between gap-4 border-t border-ink pt-4">
              <Button
                variant="secondary"
                size="sm"
                disabled={page <= 1}
                onClick={() => update('page', String(page - 1))}
              >
                Précédent
              </Button>
              <p className="font-mono text-[11px] uppercase tracking-widest tabular-nums text-subtle">
                Page {page} sur {pageCount}
              </p>
              <Button
                variant="secondary"
                size="sm"
                disabled={page >= pageCount}
                onClick={() => update('page', String(page + 1))}
              >
                Suivant
              </Button>
            </nav>
          )}
        </>
      )}
    </div>
  )
}
