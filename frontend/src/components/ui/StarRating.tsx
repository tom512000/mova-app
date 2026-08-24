import { cva, type VariantProps } from 'class-variance-authority'
import { cn } from '@/utils/cn'
import { formatRating } from '@/utils/format'

const MAX_STARS = 5
const STARS = '★'.repeat(MAX_STARS)

// tracking-normal is load-bearing: letter-spacing would add a trailing gap after the
// fifth glyph, so a percentage width would no longer line up with the glyph boundaries
// and "half a star" would sit slightly off centre.
const starRatingVariants = cva('relative inline-block select-none leading-none tracking-normal', {
  variants: {
    size: {
      sm: 'text-[13px]',
      md: 'text-base',
      lg: 'text-3xl',
    },
  },
  defaultVariants: {
    size: 'sm',
  },
})

export interface StarRatingProps extends VariantProps<typeof starRatingVariants> {
  /** Out of 5. Half-star ratings land on an exactly half-filled star. */
  rating: number | null
  className?: string
}

/**
 * Draws the rating as stars rather than a number: two identical rows of glyphs stacked,
 * the accent-coloured one clipped to the rating's share of the width. Clipping by width
 * (instead of rounding to whole/half glyphs) is what makes 3.5 render as exactly half a
 * star, and it stays truthful for the averaged ratings the dashboard shows, which are not
 * half-steps at all — 3.71 fills 71% of the fourth star instead of pretending to be 3.5.
 */
export function StarRating({ rating, size, className }: StarRatingProps) {
  if (rating === null) {
    return <span className={cn('font-mono text-subtle', className)}>—</span>
  }

  const clamped = Math.min(Math.max(rating, 0), MAX_STARS)
  const label = `${formatRating(rating)} sur ${MAX_STARS}`

  return (
    <span className={cn(starRatingVariants({ size }), className)} role="img" aria-label={label} title={label}>
      <span aria-hidden className="text-ink/20">
        {STARS}
      </span>
      {/* Sits exactly on top of the row above: same glyphs, same tracking, same font size. */}
      <span
        aria-hidden
        className="absolute left-0 top-0 overflow-hidden whitespace-nowrap text-accent"
        style={{ width: `${(clamped / MAX_STARS) * 100}%` }}
      >
        {STARS}
      </span>
    </span>
  )
}
