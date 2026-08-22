import { cn } from '@/utils/cn'

export function Skeleton({ className }: { className?: string }) {
  return <div className={cn('animate-pulse bg-surface', className)} />
}

export function SkeletonCard() {
  return (
    <div className="border border-ink/20 bg-paper p-5">
      <Skeleton className="mb-3 h-3 w-24" />
      <Skeleton className="h-8 w-32" />
    </div>
  )
}

export function SkeletonGrid({ count = 4 }: { count?: number }) {
  return (
    <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
      {Array.from({ length: count }).map((_, i) => (
        <SkeletonCard key={i} />
      ))}
    </div>
  )
}
