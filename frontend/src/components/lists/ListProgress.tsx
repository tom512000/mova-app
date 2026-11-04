import { cn } from '@/utils/cn'

/**
 * How far through a list its owner is: a rule that fills in ink, and the count beside it.
 * Used on the index card and in the list's own header, so the two always read alike.
 */
export function ListProgress({
  watched,
  total,
  size = 'sm',
  className,
}: {
  watched: number
  total: number
  size?: 'sm' | 'lg'
  className?: string
}) {
  const percent = total > 0 ? Math.round((watched / total) * 100) : 0
  const works = `œuvre${total > 1 ? 's' : ''}`

  return (
    <div className={cn('flex flex-col gap-1.5', className)}>
      <div
        role="progressbar"
        aria-valuemin={0}
        aria-valuemax={total}
        aria-valuenow={watched}
        aria-label={`${watched} ${works} vue${watched > 1 ? 's' : ''} sur ${total}`}
        className={cn('w-full border border-ink bg-paper', size === 'lg' ? 'h-3' : 'h-2')}
      >
        <div className="h-full bg-ink transition-[width] duration-500" style={{ width: `${percent}%` }} />
      </div>
      <p className={cn('flex justify-between font-mono uppercase tracking-widest text-subtle', size === 'lg' ? 'text-xs' : 'text-[11px]')}>
        <span>
          <b className="text-ink">{watched}</b> vue{watched > 1 ? 's' : ''} sur {total} {works}
        </span>
        <span className="tabular-nums">{percent} %</span>
      </p>
    </div>
  )
}
