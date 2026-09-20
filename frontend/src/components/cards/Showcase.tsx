import { useState } from 'react'
import type { Card, Id } from '@/types/api'
import { CardFace } from '@/components/cards/CardFace'
import { CardDialog } from '@/components/cards/CardDialog'
import { cn } from '@/utils/cn'

/**
 * Six slots, and whatever is in them.
 *
 * The Cabinet's one social surface. Mova has no trading floor and no other players — the
 * only way a Légendaire is ever seen by somebody else is through a shared profile, so the
 * showcase is the whole reason the collection points outward at all. Which is why it reads
 * the viewed profile, and why an empty slot is drawn rather than collapsed: an arrangement
 * of two cards in slots one and four is an arrangement, not a list of two.
 */
export function Showcase({
  slots,
  ownerDisplayName,
  isOwn,
}: {
  slots: (Card | null)[]
  ownerDisplayName: string
  isOwn: boolean
}) {
  const [openCardId, setOpenCardId] = useState<Id | null>(null)
  const filled = slots.filter((slot) => slot !== null).length

  return (
    <section className="flex flex-col gap-4">
      <div className="flex flex-wrap items-baseline justify-between gap-2 border-b border-ink pb-3">
        <h2 className="font-serif text-3xl font-black tracking-tight">
          {isOwn ? 'Ta vitrine' : `La vitrine de ${ownerDisplayName}`}
        </h2>
        {isOwn && (
          <p className="font-mono text-[10px] uppercase tracking-widest text-subtle">
            {filled} sur {slots.length} · épingle une carte depuis sa fiche
          </p>
        )}
      </div>

      <ul className="grid grid-cols-3 gap-3 sm:grid-cols-6">
        {slots.map((card, index) => (
          <li key={card?.id ?? `slot-${index}`}>
            {card !== null ? (
              <button
                type="button"
                onClick={() => setOpenCardId(card.id)}
                aria-label={card.label}
                className="hard-shadow-hover group block w-full text-left focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
              >
                <CardFace card={card} size="sm" />
              </button>
            ) : (
              <div
                className={cn(
                  'flex aspect-[2/3] w-full items-center justify-center border border-dashed border-ink/30 bg-surface',
                  'font-mono text-[10px] uppercase tracking-widest text-ink/25'
                )}
              >
                {index + 1}
              </div>
            )}
          </li>
        ))}
      </ul>

      {openCardId !== null && (
        <CardDialog key={openCardId} cardId={openCardId} onClose={() => setOpenCardId(null)} />
      )}
    </section>
  )
}
