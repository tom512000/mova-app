import { Coins, Flame, RefreshCw } from 'lucide-react'
import type { Cabinet } from '@/types/api'
import { Button } from '@/components/ui/Button'
import { formatJetons } from '@/utils/cards'

/**
 * The masthead of the Cabinet: what the collection holds, and — only when it is yours —
 * what the till holds.
 *
 * The split is the server's, not a styling choice: `balance` and everything beside it come
 * back null while another profile is being viewed, so there is nothing here to hide by hand.
 * A collection is worth showing somebody; the money belongs to whoever is doing the looking.
 */
export function CabinetHeader({
  cabinet,
  onClaimDaily,
  claiming,
}: {
  cabinet: Cabinet
  onClaimDaily: () => void
  claiming: boolean
}) {
  const till = cabinet.balance !== null

  return (
    <header className="flex flex-col gap-6 border-b-4 border-ink pb-6">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <p className="font-mono text-[10px] uppercase tracking-widest text-subtle">
            {till ? 'Collection' : `La collection de ${cabinet.ownerDisplayName}`}
          </p>
          <h1 className="font-serif text-5xl font-black tracking-tighter sm:text-6xl">Le Cabinet</h1>
        </div>

        {till && (
          <div className="flex items-center gap-3">
            <p className="flex items-center gap-2 border border-ink px-3 py-2 font-mono text-sm font-bold tabular-nums">
              <Coins className="h-4 w-4" strokeWidth={2} aria-hidden />
              {formatJetons(cabinet.balance ?? 0)}
            </p>
            {cabinet.dailyGrantAvailable === true && (
              <Button size="sm" onClick={onClaimDaily} disabled={claiming}>
                <Flame className="h-4 w-4" strokeWidth={2.5} />
                {claiming ? 'Encaissement…' : 'Prime du jour'}
              </Button>
            )}
          </div>
        )}
      </div>

      <dl className="grid grid-cols-2 gap-4 sm:grid-cols-4">
        <Figure label="Cartes obtenues" value={`${cabinet.ownedCount.toLocaleString('fr-FR')}`} hint={`sur ${cabinet.cardCount.toLocaleString('fr-FR')}`} />
        <Figure label="Œuvres vues" value={cabinet.workCount.toLocaleString('fr-FR')} />
        {till && (
          <>
            <Figure
              label="Paquets ouverts"
              value={(cabinet.packsOpened ?? 0).toLocaleString('fr-FR')}
            />
            <Figure
              label="Série"
              value={`${cabinet.streakDays ?? 0} j`}
              hint={
                cabinet.freeSalvageLeft !== null
                  ? `${formatJetons(cabinet.freeSalvageLeft)} de doublons restants aujourd’hui`
                  : undefined
              }
            />
          </>
        )}
      </dl>
    </header>
  )
}

function Figure({ label, value, hint }: { label: string; value: string; hint?: string }) {
  return (
    <div className="flex flex-col gap-1">
      <dt className="font-mono text-[10px] uppercase tracking-widest text-subtle">{label}</dt>
      <dd className="font-serif text-3xl font-black tabular-nums leading-none">{value}</dd>
      {hint !== undefined && <p className="font-body text-xs italic text-subtle">{hint}</p>}
    </div>
  )
}

/** The "not yet" state, worth its own component because it is what most visitors see first. */
export function CabinetLocked({
  cabinet,
  onRebuild,
  rebuilding,
}: {
  cabinet: Cabinet
  onRebuild: () => void
  rebuilding: boolean
}) {
  const missing = Math.max(0, cabinet.minimumWorks - cabinet.workCount)

  return (
    <section className="flex flex-col items-center gap-4 border border-dashed border-ink/40 py-20 text-center">
      <p className="font-serif text-2xl font-bold">Le Cabinet n’est pas encore ouvert</p>
      <p className="max-w-lg font-body text-sm text-subtle">
        Il faut {cabinet.minimumWorks.toLocaleString('fr-FR')} œuvres vues pour que la collection
        ait de quoi se construire. Il t’en manque <b className="text-ink">{missing}</b>.
      </p>
      <Button variant="secondary" size="sm" onClick={onRebuild} disabled={rebuilding}>
        <RefreshCw className="h-4 w-4" strokeWidth={2} />
        {rebuilding ? 'Recalcul demandé' : 'Recalculer le catalogue'}
      </Button>
    </section>
  )
}
