import type { Badge } from '@/types/api'
import { cn } from '@/utils/cn'
import { badgeTitle } from '@/utils/badges'

/**
 * A badge's face: a still from one of the works that earned it, with the level in the
 * corner.
 *
 * Square, and not the circular medallion the idea usually comes with. The stylesheet forces
 * `border-radius: 0` on everything with no exceptions, so a circle here would have to fight
 * the design system to exist — and a square stamp is the better answer anyway on a page
 * built to look like newsprint. What it ends up resembling is a postage stamp, which is
 * exactly what a badge is: proof of something, printed small.
 *
 * Greyscale like every other image in the app, and colour returns on hover the same way a
 * poster's does, so a shelf of these reads as part of the same object as the film grids.
 */
export function BadgeStamp({ badge, size = 'md' }: { badge: Badge; size?: 'sm' | 'md' }) {
  const title = badgeTitle(badge)

  return (
    <div
      className={cn(
        'relative aspect-square w-full overflow-hidden border border-ink bg-surface-2',
        size === 'sm' && 'max-w-16'
      )}
    >
      {badge.imageUrl ? (
        <img
          src={badge.imageUrl}
          alt=""
          loading="lazy"
          className="h-full w-full object-cover grayscale transition-all duration-300 group-hover:grayscale-0 group-hover:sepia-[.5]"
        />
      ) : (
        // The same dotted ground a missing poster gets, with the subject's own initial on
        // it: a badge whose every work lacks artwork still has to look like a badge.
        <div className="flex h-full w-full items-center justify-center bg-[radial-gradient(currentColor_1px,transparent_1px)] bg-size-[16px_16px] text-ink/10">
          <span className="font-serif text-2xl font-black text-ink/40">{title.slice(0, 1)}</span>
        </div>
      )}

      {/* The level, bottom-left, over a solid block so it stays legible on any still. Ink
          on paper rather than the accent: the accent is the app's one loud colour and a
          shelf of six hundred of these would be a wall of red. */}
      <span
        className="absolute bottom-0 left-0 min-w-6 border-r border-t border-ink bg-ink px-1.5 py-0.5 text-center font-mono text-[11px] font-bold leading-none text-paper tabular-nums"
        title={`Niveau ${badge.level}`}
      >
        {badge.level}
      </span>
    </div>
  )
}
