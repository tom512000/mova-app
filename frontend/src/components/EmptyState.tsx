import type { ReactNode } from 'react'

export function EmptyState({
  title,
  description,
  action,
}: {
  title: string
  description?: string
  action?: ReactNode
}) {
  return (
    <div className="flex flex-col items-center justify-center gap-3 border border-dashed border-ink/40 py-20 text-center">
      <p className="font-serif text-2xl font-bold">{title}</p>
      {description && <p className="max-w-md font-body text-sm text-subtle">{description}</p>}
      {action}
    </div>
  )
}
