import { useQuery } from '@tanstack/react-query'
import { fetchImportBatch } from '@/services/importService'
import type { ImportBatch } from '@/types/api'
import { Badge } from '@/components/ui/Badge'

const STATUS_LABEL: Record<ImportBatch['status'], string> = {
  pending: 'En attente',
  processing: 'En cours',
  completed: 'Terminé',
  completed_with_errors: 'Terminé avec erreurs',
  failed: 'Échec',
}

const STATUS_VARIANT: Record<ImportBatch['status'], 'outline' | 'solid' | 'accent'> = {
  pending: 'outline',
  processing: 'outline',
  completed: 'solid',
  completed_with_errors: 'accent',
  failed: 'accent',
}

/** Statuses an import can still move out of. Everything else is final. */
const RUNNING: ReadonlyArray<ImportBatch['status']> = ['pending', 'processing']

export function ImportBatchRow({ initial }: { initial: ImportBatch }) {
  const isRunning = RUNNING.includes(initial.status)

  const { data: batch } = useQuery({
    queryKey: ['import', initial.id],
    queryFn: () => fetchImportBatch(initial.id),
    initialData: initial,
    // A finished import never changes again, so the row it was handed is the row for good.
    // Without this, initialData counts as stale and every row refetches itself on mount —
    // thirty-eight requests fired on opening a page that already had all thirty-eight
    // answers in the history payload.
    staleTime: isRunning ? 0 : Infinity,
    refetchInterval: (query) => {
      const status = query.state.data?.status
      return status === 'pending' || status === 'processing' ? 1000 : false
    },
  })

  if (!batch) return null

  return (
    <div className="border border-ink p-4">
      <div className="flex items-center justify-between gap-3">
        <p className="font-mono text-sm font-medium">{batch.filename}</p>
        <Badge variant={STATUS_VARIANT[batch.status]}>{STATUS_LABEL[batch.status]}</Badge>
      </div>
      <div className="mt-3 h-1.5 w-full bg-surface-2">
        <div className="h-full bg-ink transition-all" style={{ width: `${batch.progressPercent}%` }} />
      </div>
      <p className="mt-2 font-mono text-[11px] text-subtle">
        {batch.rowsImported} importée(s) &middot; {batch.rowsSkipped} ignorée(s) &middot; {batch.rowsFailed} échouée(s) / {batch.rowsTotal} lignes
      </p>
      {batch.errorsSample.length > 0 && (
        <details className="mt-2 text-xs">
          <summary className="cursor-pointer font-mono uppercase tracking-widest text-accent">
            Voir {batch.errorsSample.length} erreur(s)
          </summary>
          <ul className="mt-2 list-disc space-y-1 pl-4 font-body text-subtle">
            {batch.errorsSample.map((e) => (
              <li key={e.rowNumber}>
                Ligne {e.rowNumber} : {e.errorMessage}
              </li>
            ))}
          </ul>
        </details>
      )}
    </div>
  )
}
