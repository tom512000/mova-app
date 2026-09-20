import type { Card } from '@/types/api'
import { cn } from '@/utils/cn'
import {
  RARITY_FRAME,
  RARITY_GROUND,
  RARITY_MAT,
  SUBJECT_SINGULAR,
  hasCornerMarks,
  hasInnerRule,
} from '@/utils/cards'
import { RarityCode, RarityPips } from '@/components/cards/RarityMark'

/**
 * A card, printed.
 *
 * Everything about the tier is carried by four independently readable channels — the mono
 * code in the corner, the pip count, the frame weight, and the textured mat the picture sits
 * in — so none of them has to be colour. The one exception is Légendaire, which is printed on
 * reversed-out stock: the loudest move this palette has, spent once and nowhere else.
 *
 * The layout is three stacked boxes and the order matters: a frame, a picture box that owns
 * every badge pinned to it, and an opaque caption band. Badges used to be positioned against
 * the card instead, which put the tier plate straight on top of the year and the work count;
 * and the texture used to sit on the card, which put a field of dots behind the name.
 *
 * A card without artwork is not a broken card. Studios have no logo in this database at all,
 * and plenty of people have no photo, so the typographic plate below is the common case of a
 * shared fallback rather than an error state — the same thing BadgeStamp does with a dotted
 * ground and an initial, given more room.
 */
export function CardFace({
  card,
  size = 'md',
  showLabel = true,
  className,
}: {
  card: Card
  size?: 'sm' | 'md' | 'lg'
  showLabel?: boolean
  className?: string
}) {
  const legendary = card.rarity === 'legendary'
  const dimmed = !card.owned

  return (
    <div
      className={cn(
        'relative flex aspect-[2/3] w-full flex-col overflow-hidden',
        RARITY_FRAME[card.rarity],
        // Not owned: drawn in place, in catalogue order, never hidden and never reshuffled
        // behind what is owned — the property TrophyShelf relies on for its locked slots.
        dimmed && 'opacity-55',
        className
      )}
    >
      {/* An inner rule from Super Rare up: a second frame inside the first, the way a
          printed collectible signals a better print run. */}
      {hasInnerRule(card.rarity) && (
        <span
          aria-hidden
          className={cn(
            'pointer-events-none absolute inset-[3px] border',
            legendary ? 'border-paper/50' : 'border-ink/35'
          )}
        />
      )}

      {hasCornerMarks(card.rarity) && (
        <>
          {['left-0 top-0', 'right-0 top-0', 'left-0 bottom-0', 'right-0 bottom-0'].map((corner) => (
            <span
              key={corner}
              aria-hidden
              className={cn(
                'pointer-events-none absolute h-2 w-2',
                legendary ? 'bg-paper' : 'bg-ink',
                corner
              )}
            />
          ))}
        </>
      )}

      {/* The picture, and everything pinned to it.
          Every badge belongs to this box rather than to the card, so that none of them can
          land on the caption underneath — which is exactly what they did when they were
          positioned against the card itself. */}
      <div className="relative flex-1 overflow-hidden">
        <div className={cn('h-full w-full', RARITY_GROUND[card.rarity], RARITY_MAT[card.rarity])}>
          {card.imageUrl ? (
            <img
              src={card.imageUrl}
              alt=""
              loading="lazy"
              className={cn(
                'h-full w-full object-cover transition-all duration-300',
                // The app's rule for every image: grey until you look at it.
                legendary ? 'grayscale invert group-hover:invert-0' : 'grayscale group-hover:grayscale-0'
              )}
            />
          ) : (
            <TypePlate card={card} size={size} legendary={legendary} />
          )}
        </div>

        <RarityCode
          rarity={card.rarity}
          inverted={legendary}
          className="absolute bottom-0 left-0 border-r border-t border-current"
        />

        {/* Copies past the first, bottom-right. Absent at one, because "×1" on every card in
            a grid of three thousand is noise. */}
        {card.copies > 1 && (
          <span
            className={cn(
              'absolute bottom-0 right-0 border-l border-t border-current px-1.5 py-0.5 font-mono text-[10px] font-bold leading-none tabular-nums',
              legendary ? 'bg-paper text-ink' : 'bg-ink text-paper'
            )}
            title={`${card.copies} exemplaires`}
          >
            ×{card.copies}
          </span>
        )}

        {/* The library moved under an owned card and its tier with it. Shown rather than
            hidden: a collection that quietly downgraded what you own would be worse. */}
        {card.owned && card.liveRarity !== card.rarity && (
          <span
            className={cn(
              'absolute right-0 top-0 border-b border-l border-current px-1 py-0.5 font-mono text-[10px] leading-none',
              legendary ? 'bg-paper text-ink' : 'bg-ink text-paper'
            )}
            title="Réévaluée depuis ce tirage"
          >
            ↕
          </span>
        )}
      </div>

      {showLabel && (
        <div
          className={cn(
            // An opaque caption band. Nothing textured is allowed behind a name.
            'relative border-t px-2 py-1.5',
            legendary ? 'border-paper/40 bg-ink' : 'border-ink/25 bg-surface'
          )}
        >
          <p
            className={cn(
              'truncate font-serif font-black leading-tight',
              size === 'sm' ? 'text-xs' : 'text-sm'
            )}
            title={card.label}
          >
            {card.label}
          </p>
          <p className="mt-0.5 flex items-center justify-between gap-2">
            <span
              className={cn(
                'truncate font-mono text-[10px] uppercase tracking-widest',
                legendary ? 'text-paper/65' : 'text-subtle'
              )}
            >
              {card.subject === 'work' && card.releaseYear !== null
                ? card.releaseYear
                : card.subject === 'work'
                  ? SUBJECT_SINGULAR.work
                  : `${card.workCount} œuvre${card.workCount > 1 ? 's' : ''}`}
            </span>
            <RarityPips rarity={card.rarity} className="shrink-0" />
          </p>
        </div>
      )}
    </div>
  )
}

/**
 * The face of a card with no picture.
 *
 * Auto-sized in three buckets by name length rather than shrunk to fit: a studio called
 * "Pixar" and one called "Metro-Goldwyn-Mayer" should both fill the plate, and a single
 * scale that suits neither would make the short ones look like a mistake.
 */
function TypePlate({
  card,
  size,
  legendary,
}: {
  card: Card
  size: 'sm' | 'md' | 'lg'
  legendary: boolean
}) {
  const length = card.label.length
  const scale =
    length <= 12 ? (size === 'sm' ? 'text-xl' : 'text-4xl')
    : length <= 24 ? (size === 'sm' ? 'text-base' : 'text-2xl')
    : size === 'sm' ? 'text-xs' : 'text-lg'

  return (
    <div
      className={cn(
        'flex h-full w-full items-center justify-center p-3 text-center',
        // The same dotted ground a missing poster gets elsewhere in the app — kept faint,
        // since the tier's own ground may already be showing through behind it.
        'bg-[radial-gradient(currentColor_1px,transparent_1px)] bg-size-[14px_14px]',
        legendary ? 'text-paper/10' : 'text-ink/[0.07]'
      )}
    >
      <span
        className={cn(
          'font-serif font-black leading-none tracking-tight',
          scale,
          legendary ? 'text-paper' : 'text-ink'
        )}
      >
        {card.label}
      </span>
    </div>
  )
}

/** The back of a card, for the moment before it turns over. */
export function CardBack({ className }: { className?: string }) {
  return (
    <div
      className={cn(
        'flex aspect-[2/3] w-full items-center justify-center border-2 border-ink bg-ink',
        className
      )}
    >
      <div
        aria-hidden
        className="flex h-full w-full items-center justify-center bg-[repeating-linear-gradient(45deg,transparent_0_6px,rgba(255,255,255,0.06)_6px_12px)]"
      >
        <span className="font-serif text-3xl font-black tracking-tighter text-paper/70">M</span>
      </div>
    </div>
  )
}
