import { Lock } from 'lucide-react'
import type { CardPackKind } from '@/types/api'
import { Button } from '@/components/ui/Button'
import { cn } from '@/utils/cn'
import { FREE_PACK_NOTE, PACK_COPY, RARITY_COPY, formatJetons } from '@/utils/cards'

const ORDER: CardPackKind[] = ['free', 'reel', 'boxset']

/**
 * The three packs, with what each of them promises written on it.
 *
 * The guaranteed floor is printed rather than left to be discovered, and so is the free
 * pack's catch. A collection game that hides its odds is a game people stop trusting, and
 * this one's whole balance rests on the free pack being generous *and* deliberately poor —
 * which is only fair if it says so.
 */
export function PackShelf({
  balance,
  disabled,
  pending,
  onOpen,
}: {
  balance: number
  disabled: boolean
  pending: CardPackKind | null
  onOpen: (kind: CardPackKind) => void
}) {
  return (
    <div className="flex flex-col gap-4">
      <ul className="grid grid-cols-1 gap-4 sm:grid-cols-3">
        {ORDER.map((kind) => {
          const pack = PACK_COPY[kind]
          const tooPoor = pack.price > balance
          const blocked = disabled || tooPoor

          return (
            <li
              key={kind}
              className={cn(
                'flex flex-col gap-3 border p-4',
                blocked ? 'border-ink/30 bg-surface' : 'border-ink bg-paper'
              )}
            >
              <div className="flex items-baseline justify-between gap-2 border-b border-ink/20 pb-2">
                <h3 className="font-serif text-xl font-black tracking-tight">{pack.name}</h3>
                <span className="font-mono text-[11px] uppercase tracking-widest tabular-nums text-subtle">
                  {pack.price === 0 ? 'Gratuit' : formatJetons(pack.price)}
                </span>
              </div>

              <p className="font-body text-sm italic text-subtle">{pack.tagline}</p>

              <p className="font-mono text-[10px] uppercase tracking-widest text-subtle">
                {pack.cards} cartes · garantie {RARITY_COPY[pack.floor].name}
              </p>

              <Button
                variant={kind === 'free' ? 'secondary' : 'primary'}
                size="sm"
                className="mt-auto"
                disabled={blocked || pending !== null}
                onClick={() => onOpen(kind)}
              >
                {blocked && <Lock className="h-3.5 w-3.5" strokeWidth={2.5} />}
                {pending === kind ? 'Ouverture…' : tooPoor ? 'Trop cher' : 'Ouvrir'}
              </Button>
            </li>
          )
        })}
      </ul>

      <p className="border-l-2 border-ink/30 pl-3 font-body text-sm italic text-subtle">
        {FREE_PACK_NOTE}
      </p>
    </div>
  )
}
