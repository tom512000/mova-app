import { useState } from 'react'
import type { Card, Id } from '@/types/api'
import { CardFace } from '@/components/cards/CardFace'
import { CardDialog } from '@/components/cards/CardDialog'

/**
 * A shelf of cards.
 *
 * Cards that are not owned are drawn in place and in catalogue order, never hidden and never
 * pushed behind the ones that are — the same rule TrophyShelf keeps for its locked slots:
 * pulling a card fills a gap rather than reshuffling the page. That is what makes an album
 * feel like an album instead of a feed.
 *
 * A card opens a dialog rather than navigating. Looking at one is a glance, and a full page
 * change would cost the scroll position and the filters on every single look.
 */
export function CardGrid({ cards }: { cards: Card[] }) {
  const [openCardId, setOpenCardId] = useState<Id | null>(null)

  return (
    <>
      <ul className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5 xl:grid-cols-6">
        {cards.map((card) => (
          <li key={card.id}>
            <button
              type="button"
              onClick={() => setOpenCardId(card.id)}
              aria-label={card.label}
              className="hard-shadow-hover group block w-full text-left focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
            >
              <CardFace card={card} size="sm" />
            </button>
          </li>
        ))}
      </ul>

      {/* Keyed on the card so that opening a second one remounts the dialog rather than
          leaving the previous card's data on screen while the new one loads. */}
      {openCardId !== null && (
        <CardDialog key={openCardId} cardId={openCardId} onClose={() => setOpenCardId(null)} />
      )}
    </>
  )
}
