import type { ReactNode } from 'react'
import { cn } from '@/utils/cn'

export function StatCard({
  label,
  value,
  hint,
  className,
}: {
  label: string
  value: ReactNode
  hint?: string
  className?: string
}) {
  return (
    <div className={cn('hard-shadow-hover border border-ink bg-paper p-5', className)}>
      <p className="font-mono text-[10px] uppercase tracking-widest text-subtle">{label}</p>
      <p className="mt-2 truncate font-mono text-3xl font-semibold tabular-nums">{value}</p>
      {hint && <p className="mt-1 truncate text-xs text-subtle">{hint}</p>}
    </div>
  )
}
