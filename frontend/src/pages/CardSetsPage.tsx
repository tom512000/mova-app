import { useState } from 'react'
import type { CardSetFamily } from '@/types/api'
import { PageMeta } from '@/components/PageMeta'
import { EmptyState } from '@/components/EmptyState'
import { ErrorState } from '@/components/ErrorState'
import { SkeletonSetList } from '@/components/Skeleton'
import { FilterSelect, Option } from '@/components/ui/FilterSelect'
import { SetPanel } from '@/components/cards/SetPanel'
import { FeatShelf } from '@/components/cards/FeatShelf'
import { useCabinet, useCardFeats, useCardSets } from '@/hooks/useCabinet'
import { useSession } from '@/hooks/useSession'
import { SET_FAMILY_LABEL } from '@/utils/cards'
import { apiErrorMessage } from '@/utils/apiError'

const FAMILIES: CardSetFamily[] = ['decade', 'genre', 'country', 'studio', 'franchise']

/**
 * Sets to close, and the feats that come of closing them.
 *
 * Both are derived on every request, so neither can drift from the library the way a stored
 * tally would. They share a page because they answer the same question from two directions:
 * what is nearly finished, and what finishing things has been worth.
 */
export function CardSetsPage() {
  const { isViewingOtherProfile } = useSession()
  const [family, setFamily] = useState<CardSetFamily | ''>('')
  const [claimingKey, setClaimingKey] = useState<string | null>(null)

  const sets = useCardSets(family === '' ? undefined : family)
  const feats = useCardFeats()
  const { claim } = useCabinet()

  return (
    <div className="flex flex-col gap-10">
      <PageMeta title="Les séries" />

      <header className="flex flex-col gap-3 border-b-4 border-ink pb-6">
        <h1 className="font-serif text-5xl font-black tracking-tighter sm:text-6xl">Les séries</h1>
        <p className="max-w-2xl font-body text-sm text-subtle">
          Chaque décennie, chaque genre, chaque studio de ta bibliothèque forme une série à
          réunir. Une série terminée se réclame une fois.
        </p>
      </header>

      <FilterSelect
        label="Famille"
        value={family}
        onChange={(value) => setFamily(value as CardSetFamily | '')}
        className="max-w-xs"
      >
        <Option value="">Toutes</Option>
        {FAMILIES.map((value) => (
          <Option key={value} value={value}>
            {SET_FAMILY_LABEL[value]}
          </Option>
        ))}
      </FilterSelect>

      {claim.isError && (
        <ErrorState message={apiErrorMessage(claim.error, 'La récompense n’a pas pu être réclamée.')} />
      )}

      {sets.isLoading && <SkeletonSetList />}
      {sets.isError && <ErrorState message={apiErrorMessage(sets.error, 'Les séries sont introuvables.')} />}

      {sets.data !== undefined && sets.data.length === 0 && (
        <EmptyState
          title="Aucune série pour l’instant"
          description="Une série a besoin d’au moins cinq cartes pour exister."
        />
      )}

      {sets.data !== undefined && sets.data.length > 0 && (
        <ul className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {sets.data.map((set) => (
            <SetPanel
              key={`${set.family}-${set.key}`}
              set={set}
              canClaim={!isViewingOtherProfile}
              claiming={claimingKey === `${set.family}-${set.key}` && claim.isPending}
              onClaim={() => {
                setClaimingKey(`${set.family}-${set.key}`)
                claim.mutate(
                  { family: set.family, key: set.key },
                  { onSettled: () => setClaimingKey(null) }
                )
              }}
            />
          ))}
        </ul>
      )}

      {feats.data !== undefined && <FeatShelf feats={feats.data} />}
    </div>
  )
}
