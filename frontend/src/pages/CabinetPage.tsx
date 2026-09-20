import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import type { CardPackKind, PackResult } from '@/types/api'
import { PageMeta } from '@/components/PageMeta'
import { ErrorState } from '@/components/ErrorState'
import { SkeletonCabinet } from '@/components/Skeleton'
import { CabinetHeader, CabinetLocked } from '@/components/cards/CabinetHeader'
import { PackShelf } from '@/components/cards/PackShelf'
import { PackOpener } from '@/components/cards/PackOpener'
import { Showcase } from '@/components/cards/Showcase'
import { CardGrid } from '@/components/cards/CardGrid'
import { buttonVariants } from '@/components/ui/Button'
import { SectionLink } from '@/components/ui/SectionLink'
import { useCabinet, useShowcase } from '@/hooks/useCabinet'
import { useSession } from '@/hooks/useSession'
import { fetchCards } from '@/services/cardsService'
import { apiErrorMessage } from '@/utils/apiError'
import { cn } from '@/utils/cn'

/**
 * The hall: the till, the packs, the last few finds, and the showcase.
 *
 * Read on a shared profile and written only on your own, which is why the pack shelf is
 * behind `!isViewingOtherProfile` rather than behind a nav flag — the same in-component
 * gate AppLayout already applies to the Share button. The album and the showcase stay
 * visible either way, because showing somebody your collection is the point of having one
 * in an app with no other players.
 */
export function CabinetPage() {
  const { isViewingOtherProfile } = useSession()
  const { cabinet, daily, open, rebuild } = useCabinet()
  const { showcase } = useShowcase()
  const [opened, setOpened] = useState<PackResult | null>(null)
  const [pending, setPending] = useState<CardPackKind | null>(null)

  const recent = useQuery({
    queryKey: ['cards', 'list', 'recent'],
    queryFn: () => fetchCards({ owned: true, sort: 'recent', perPage: 12 }),
    staleTime: 30_000,
    enabled: cabinet.data?.unlocked === true,
  })

  const handleOpen = (kind: CardPackKind) => {
    setPending(kind)
    open.mutate(kind, {
      onSuccess: (result) => setOpened(result),
      onSettled: () => setPending(null),
    })
  }

  if (cabinet.isLoading) return <SkeletonCabinet />

  // Only when there is genuinely nothing to show. TanStack keeps the last good data when a
  // *refetch* fails, so tearing the page down on isError alone replaced the whole Cabinet —
  // packs, showcase and all — with an error box over a single failed background request,
  // while the data to render it was sitting right there in the cache.
  if (cabinet.data === undefined) {
    return <ErrorState message={apiErrorMessage(cabinet.error, 'Le Cabinet est introuvable.')} />
  }

  const owner = !isViewingOtherProfile

  return (
    <div className="flex flex-col gap-10">
      <PageMeta title="Le Cabinet" />

      <CabinetHeader
        cabinet={cabinet.data}
        claiming={daily.isPending}
        onClaimDaily={() => daily.mutate()}
      />

      {/* A failed grant used to say nothing at all: the notice below only covers success, so
          a refused or dropped request left the button simply doing nothing. */}
      {daily.isError && (
        <ErrorState message={apiErrorMessage(daily.error, 'La prime n’a pas pu être encaissée.')} />
      )}

      {daily.data?.granted === true && (
        <p className="border-l-2 border-accent bg-accent/5 px-3 py-2 font-body text-sm">
          Prime du jour encaissée : <b>+{daily.data.amount} jetons</b>
          {daily.data.streakDays > 1 && ` · ${daily.data.streakDays} jours d’affilée`}
        </p>
      )}

      {!cabinet.data.unlocked ? (
        <CabinetLocked
          cabinet={cabinet.data}
          rebuilding={rebuild.isPending || rebuild.isSuccess}
          onRebuild={() => rebuild.mutate()}
        />
      ) : (
        <>
          {owner && (
            <section className="flex flex-col gap-4">
              <h2 className="border-b border-ink pb-3 font-serif text-3xl font-black tracking-tight">
                Les paquets
              </h2>

              {open.isError && <ErrorState message={apiErrorMessage(open.error, 'Le paquet n’a pas pu être ouvert.')} />}

              {/* The shelf stays. It used to be replaced by the reveal, which meant that
                  after opening one pack there was no way to open another without first
                  dismissing the cards — and anyone who scrolled away instead simply had no
                  packs on the page any more. The reveal belongs under the shelf, not in
                  place of it. */}
              <PackShelf
                balance={cabinet.data.balance ?? 0}
                disabled={false}
                pending={pending}
                onOpen={handleOpen}
              />

              {opened !== null && (
                <PackOpener result={opened} onDone={() => setOpened(null)} />
              )}
            </section>
          )}

          {showcase.data !== undefined && (
            <Showcase
              slots={showcase.data.slots}
              ownerDisplayName={showcase.data.ownerDisplayName}
              isOwn={owner}
            />
          )}

          {recent.data !== undefined && recent.data.items.length > 0 && (
            <section className="flex flex-col gap-4">
              <div className="flex flex-wrap items-baseline justify-between gap-2 border-b border-ink pb-3">
                <h2 className="font-serif text-3xl font-black tracking-tight">Dernières trouvailles</h2>
                <SectionLink to="/cabinet/album">Tout l’album</SectionLink>
              </div>
              <CardGrid cards={recent.data.items} />
            </section>
          )}

          <nav className="flex flex-wrap gap-3 border-t border-ink pt-6">
            <Link to="/cabinet/album" className={cn(buttonVariants({ variant: 'secondary', size: 'sm' }))}>
              L’album
            </Link>
            <Link to="/cabinet/series" className={cn(buttonVariants({ variant: 'secondary', size: 'sm' }))}>
              Les séries
            </Link>
          </nav>
        </>
      )}
    </div>
  )
}
