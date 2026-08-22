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
    <div className="flex flex-col items-center justify-center gap-2 rounded-xl border border-dashed border-neutral-300 py-16 text-center dark:border-neutral-700">
      <p className="text-lg font-medium text-neutral-700 dark:text-neutral-300">{title}</p>
      {description && <p className="max-w-md text-sm text-neutral-500 dark:text-neutral-400">{description}</p>}
      {action}
    </div>
  )
}
