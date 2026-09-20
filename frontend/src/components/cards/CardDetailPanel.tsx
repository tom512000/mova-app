import { useQuery } from '@tanstack/react-query'
import { Pin, PinOff } from 'lucide-react'
import type { Id } from '@/types/api'
import { ErrorState } from '@/components/ErrorState'
import { Skeleton } from '@/components/Skeleton'
import { Button } from '@/components/ui/Button'
import { CardFace } from '@/components/cards/CardFace'
import { RarityMark } from '@/components/cards/RarityMark'
import { useShowcase } from '@/hooks/useCabinet'
import { useSession } from '@/hooks/useSession'
import { fetchCard } from '@/services/cardsService'
import { RARITY_COPY, SUBJECT_SINGULAR, formatPercentile } from '@/utils/cards'
import { apiErrorMessage } from '@/utils/apiError'

/**
 * One card, and why it is worth what it is worth.
 *
 * Its own component because it is shown two ways: in a dialog when a card is clicked in a
 * grid, and as a page when somebody arrives on its URL directly. Opening a card is a glance
 * rather than a destination — a full navigation for a hundred-word panel is a lot of
 * ceremony — but the URL has to keep working, so both read this.
 *
 * The breakdown is here for a specific reason. Rarity is a percentile over a score nobody
 * can see, and a person's score comes entirely from their footprint in this library — so a
 * beloved director of one obscure film can outrank a household name. That is arguably right
 * for a collection built out of somebody's own library, and it will still look wrong the
 * first time it happens. Printing the standing makes it explicable instead of arbitrary.
 */
export function CardDetailPanel({ cardId }: { cardId: Id }) {
  const { isViewingOtherProfile } = useSession()
  const { showcase, save } = useShowcase()

  const { data, isLoading, isError, error } = useQuery({
    queryKey: ['cards', 'detail', cardId],
    queryFn: () => fetchCard(cardId),
  })

  if (isLoading) {
    return (
      <div className="grid grid-cols-1 gap-8 sm:grid-cols-[minmax(0,240px)_1fr]">
        <Skeleton className="mx-auto aspect-[2/3] w-full max-w-[240px]" />
        <div className="flex flex-col gap-3">
          <Skeleton className="h-10 w-3/4" />
          <Skeleton className="h-4 w-1/3" />
          <Skeleton className="h-20 w-full" />
        </div>
      </div>
    )
  }

  if (isError || data === undefined) {
    return <ErrorState message={apiErrorMessage(error, 'Cette carte est introuvable.')} />
  }

  const card = data.card
  const slots = showcase.data?.slots ?? []
  const pinned = card.showcasePosition !== null
  const firstFreeSlot = slots.findIndex((slot) => slot === null)
  const canPin = !isViewingOtherProfile && card.owned && (pinned || firstFreeSlot !== -1)

  const togglePin = () => {
    const ids = slots.map((slot) => slot?.id ?? null)
    if (pinned) {
      ids[(card.showcasePosition ?? 1) - 1] = null
    } else if (firstFreeSlot !== -1) {
      ids[firstFreeSlot] = card.id
    }
    save.mutate(ids)
  }

  return (
    <div className="grid grid-cols-1 gap-6 sm:grid-cols-[minmax(0,240px)_1fr] sm:gap-8">
      <div className="group mx-auto w-full max-w-[240px]">
        <CardFace card={card} size="lg" showLabel={false} />
      </div>

      <div className="flex flex-col gap-5">
        <header className="flex flex-col gap-2 border-b-2 border-ink pb-4">
          <p className="font-mono text-[10px] uppercase tracking-widest text-subtle">
            {SUBJECT_SINGULAR[card.subject]}
            {card.releaseYear !== null && ` · ${card.releaseYear}`}
          </p>
          <h2 className="font-serif text-3xl font-black tracking-tighter sm:text-4xl">
            {card.label}
          </h2>
          <RarityMark rarity={card.rarity} />
        </header>

        {data.revalued && (
          <p className="border-l-2 border-ink/40 pl-3 font-body text-sm italic text-subtle">
            ↕ Réévaluée depuis ton tirage : cette carte vaudrait{' '}
            <b className="text-ink">{RARITY_COPY[card.liveRarity].name}</b> dans le catalogue
            d’aujourd’hui. Elle reste {RARITY_COPY[card.rarity].name} dans ta collection — ce que
            tu as tiré, tu le gardes.
          </p>
        )}

        <dl className="grid grid-cols-2 gap-4 sm:grid-cols-3">
          <Figure label="Score" value={data.score.toFixed(1)} />
          <Figure label="Rang" value={`#${data.catalogueRank}`} />
          <Figure
            label={`Parmi les ${SUBJECT_SINGULAR[card.subject].toLowerCase()}s`}
            value={formatPercentile(card.percentile)}
          />
          {card.subject !== 'work' && (
            <Figure label="Œuvres touchées" value={String(card.workCount)} />
          )}
          <Figure label="Exemplaires" value={card.owned ? String(card.copies) : '—'} />
          {data.firstOwnedAt !== null && (
            <Figure
              label="Premier tirage"
              value={new Date(data.firstOwnedAt).toLocaleDateString('fr-FR')}
            />
          )}
        </dl>

        {!card.inCatalogue && (
          <p className="border-l-2 border-accent bg-accent/5 px-3 py-2 font-body text-sm">
            Cette carte est hors catalogue : son sujet a quitté la bibliothèque. Elle reste dans ta
            collection, mais aucun paquet ne peut plus la donner.
          </p>
        )}

        {!card.owned && (
          <p className="font-body text-sm italic text-subtle">Pas encore dans ta collection.</p>
        )}

        {canPin && (
          <div>
            <Button variant="secondary" size="sm" onClick={togglePin} disabled={save.isPending}>
              {pinned ? (
                <>
                  <PinOff className="h-4 w-4" strokeWidth={2} />
                  Retirer de la vitrine
                </>
              ) : (
                <>
                  <Pin className="h-4 w-4" strokeWidth={2} />
                  Mettre en vitrine
                </>
              )}
            </Button>
            {save.isError && (
              <p className="mt-2 font-body text-sm text-accent">
                {apiErrorMessage(save.error, 'La vitrine n’a pas pu être modifiée.')}
              </p>
            )}
          </div>
        )}
      </div>
    </div>
  )
}

function Figure({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex flex-col gap-1">
      <dt className="font-mono text-[10px] uppercase tracking-widest text-subtle">{label}</dt>
      <dd className="font-serif text-2xl font-black tabular-nums leading-none">{value}</dd>
    </div>
  )
}
