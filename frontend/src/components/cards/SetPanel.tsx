import { Check, Gift } from 'lucide-react'
import type { CardSet } from '@/types/api'
import { Button } from '@/components/ui/Button'
import { cn } from '@/utils/cn'
import { formatJetons, setTitle } from '@/utils/cards'

/**
 * One set, and whether it is finished.
 *
 * A completed set that has not been claimed shouts; a claimed one goes quiet. The bonus is
 * taken by hand rather than paid automatically on the pull that completed it, which is a
 * deliberate choice: it keeps the write owner-only and obvious, it gives the album a reason
 * to be visited, and it keeps opening a pack from having to scan every set in the library.
 */
export function SetPanel({
  set,
  canClaim,
  claiming,
  onClaim,
}: {
  set: CardSet
  canClaim: boolean
  claiming: boolean
  onClaim: () => void
}) {
  const share = set.total === 0 ? 0 : set.owned / set.total
  const unclaimed = set.complete && !set.claimed

  return (
    <li
      className={cn(
        'flex flex-col gap-3 border p-4',
        unclaimed ? 'border-ink bg-paper' : set.complete ? 'border-ink/50 bg-surface' : 'border-ink/30 bg-surface'
      )}
    >
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0">
          <h3 className="truncate font-serif text-lg font-black tracking-tight" title={set.label}>
            {setTitle(set.family, set.label)}
          </h3>
          <p className="font-mono text-[10px] uppercase tracking-widest tabular-nums text-subtle">
            {set.owned} / {set.total} cartes
            {set.rarePlus > 0 && ` · ${set.ownedRarePlus}/${set.rarePlus} rares+`}
          </p>
        </div>

        {set.complete && (
          <span
            className={cn(
              'shrink-0 border px-1.5 py-0.5 font-mono text-[10px] uppercase leading-none tracking-widest',
              set.claimed ? 'border-ink/40 text-subtle' : 'border-ink bg-ink text-paper'
            )}
          >
            {set.claimed ? 'Réclamée' : 'Complète'}
          </span>
        )}
      </div>

      <span className="block h-1.5 w-full bg-ink/10">
        <span
          className={cn('block h-full', set.complete ? 'bg-ink' : 'bg-accent')}
          style={{ width: `${share * 100}%` }}
        />
      </span>

      {unclaimed && canClaim && (
        <Button size="sm" onClick={onClaim} disabled={claiming}>
          <Gift className="h-4 w-4" strokeWidth={2.5} />
          {claiming ? 'Réclamation…' : `Réclamer ${formatJetons(set.bonus)}`}
        </Button>
      )}

      {set.claimed && (
        <p className="flex items-center gap-1.5 font-mono text-[10px] uppercase tracking-widest text-subtle">
          <Check className="h-3 w-3" strokeWidth={3} aria-hidden />
          {formatJetons(set.bonus)} encaissés
        </p>
      )}
    </li>
  )
}
