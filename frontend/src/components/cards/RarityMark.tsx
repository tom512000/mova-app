import type { CardRarity } from '@/types/api'
import { cn } from '@/utils/cn'
import { RARITY_COPY } from '@/utils/cards'

/**
 * The tier, written down.
 *
 * A reversed-out mono plate in the corner, exactly where BadgeStamp puts its level chip and
 * for the same reason: a tier should be *readable*, not merely signalled. Colour carries no
 * meaning anywhere in this app, so the word is the signal — the frame weight, the pips and
 * the ground behind the card are the redundancy, not the other way round.
 */
export function RarityCode({
  rarity,
  inverted = false,
  className,
}: {
  rarity: CardRarity
  /** On a Légendaire the card itself is already reversed out, so the plate flips back. */
  inverted?: boolean
  className?: string
}) {
  const copy = RARITY_COPY[rarity]

  return (
    <span
      className={cn(
        'min-w-6 px-1.5 py-0.5 text-center font-mono text-[10px] font-bold uppercase leading-none tracking-widest',
        inverted ? 'bg-paper text-ink' : 'bg-ink text-paper',
        className
      )}
      title={copy.name}
    >
      {copy.code}
    </span>
  )
}

/**
 * Six squares, filled to the tier.
 *
 * The channel that survives being shrunk: at thumbnail size "Super Rare" is a grey smudge
 * and four filled squares are still four filled squares.
 */
export function RarityPips({
  rarity,
  className,
}: {
  rarity: CardRarity
  className?: string
}) {
  const filled = RARITY_COPY[rarity].pips

  return (
    <span
      className={cn('inline-flex items-center gap-[2px]', className)}
      role="img"
      aria-label={RARITY_COPY[rarity].name}
    >
      {Array.from({ length: 6 }, (_, index) => (
        <span
          key={index}
          aria-hidden
          className={cn(
            'block h-[5px] w-[5px] border border-current',
            index < filled ? 'bg-current' : 'opacity-30'
          )}
        />
      ))}
    </span>
  )
}

/**
 * The tier spelled out, on one plate.
 *
 * Wherever there is room — a card's own page, a reveal — the word goes *inside* the
 * rectangle rather than beside it. A plate reading "C" with "COMMUNE" set next to it is the
 * abbreviation and its own expansion side by side, which says one thing twice and reads as
 * a label that forgot to finish. The two-letter code is for the corner of a thumbnail, where
 * the word genuinely does not fit.
 */
export function RarityMark({
  rarity,
  inverted = false,
  className,
}: {
  rarity: CardRarity
  inverted?: boolean
  className?: string
}) {
  return (
    <span className={cn('inline-flex items-center gap-2', className)}>
      <span
        className={cn(
          'px-2 py-1 font-mono text-[11px] font-bold uppercase leading-none tracking-widest',
          inverted ? 'bg-paper text-ink' : 'bg-ink text-paper'
        )}
      >
        {RARITY_COPY[rarity].name}
      </span>
      <RarityPips rarity={rarity} className="text-ink" />
    </span>
  )
}
