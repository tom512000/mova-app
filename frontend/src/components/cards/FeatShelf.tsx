import { Lock } from 'lucide-react'
import type { CardFeat } from '@/types/api'
import { cn } from '@/utils/cn'
import { FEAT_COPY, FEAT_FAMILIES, FEAT_FAMILY_LABEL } from '@/utils/cards'

/**
 * The ten card feats.
 *
 * Deliberately the same visual language as TrophyShelf rather than a new one: they are the
 * same kind of object — a fixed catalogue of named things with rungs — and a page that
 * invented a third way of drawing "not yet won" would be a page with three ways of drawing
 * it. Locked feats keep their place in catalogue order, dashed and faint, with the Lock
 * glyph; winning one fills its slot instead of reshuffling the shelf.
 */
export function FeatShelf({ feats }: { feats: CardFeat[] }) {
  const won = feats.filter((feat) => feat.level > 0).length

  return (
    <section className="flex flex-col gap-6">
      <div className="flex flex-wrap items-baseline justify-between gap-2 border-b border-ink pb-3">
        <h2 className="font-serif text-3xl font-black tracking-tight">Hauts faits</h2>
        <p className="font-mono text-[11px] uppercase tracking-widest text-subtle">
          <b className="text-ink">{won}</b> sur {feats.length} obtenus
        </p>
      </div>

      {FEAT_FAMILIES.map((family) => {
        const inFamily = feats.filter((feat) => feat.family === family)
        if (inFamily.length === 0) return null

        return (
          <div key={family} className="flex flex-col gap-3">
            <h3 className="font-mono text-[10px] uppercase tracking-widest text-subtle">
              {FEAT_FAMILY_LABEL[family]}
            </h3>
            <ul className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
              {inFamily.map((feat) => (
                <FeatCard key={feat.key} feat={feat} />
              ))}
            </ul>
          </div>
        )
      })}
    </section>
  )
}

function FeatCard({ feat }: { feat: CardFeat }) {
  const copy = FEAT_COPY[feat.key]
  const won = feat.level > 0
  const ceiling = feat.nextTier ?? feat.currentTier
  const floor = won ? feat.currentTier : 0
  const share = Math.min(1, Math.max(0, (feat.value - floor) / Math.max(1, ceiling - floor)))

  return (
    <li
      className={cn(
        'flex gap-3 border p-3',
        won ? 'border-ink bg-paper' : 'border-ink/30 bg-surface'
      )}
    >
      <div
        className={cn(
          'relative flex h-16 w-16 shrink-0 items-center justify-center border font-serif text-2xl font-black',
          won ? 'border-ink bg-paper text-ink' : 'border-dashed border-ink/40 bg-surface text-ink/25'
        )}
      >
        {won ? feat.level : <Lock className="h-4 w-4" strokeWidth={2} />}
      </div>

      <div className="flex min-w-0 flex-1 flex-col gap-1">
        <p className={cn('truncate font-serif text-base font-black', !won && 'text-subtle')}>
          {copy.name}
        </p>
        <p className="font-body text-xs italic text-subtle">
          {won ? copy.tagline : copy.condition}
        </p>

        <p className="mt-auto font-mono text-[10px] uppercase tracking-widest tabular-nums text-subtle">
          {copy.unit(feat.value)}
          {feat.nextTier !== null && ` · ${copy.unit(feat.nextTier)} au suivant`}
        </p>

        {/* Measured from the rung already paid for, not from zero — the same reading the
            badge shelf takes, and the only one where a bar at 10% means 10% of the work
            actually left to do. */}
        <span className="block h-1.5 w-full bg-ink/10">
          <span className="block h-full bg-accent" style={{ width: `${share * 100}%` }} />
        </span>
      </div>
    </li>
  )
}
