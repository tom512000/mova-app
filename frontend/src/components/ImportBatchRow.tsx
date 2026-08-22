import { useQuery } from '@tanstack/react-query'
import { fetchImportBatch } from '@/services/importService'
import type { ImportBatch } from '@/types/api'

const STATUS_LABEL: Record<ImportBatch['status'], string> = {
  pending: 'En attente',
  processing: 'En cours',
  completed: 'Terminé',
  completed_with_errors: 'Terminé avec erreurs',
  failed: 'Échec',
}

const STATUS_COLOR: Record<ImportBatch['status'], string> = {
  pending: 'bg-neutral-200 text-neutral-700 dark:bg-neutral-700 dark:text-neutral-200',
  processing: 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-400',
  completed: 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-400',
  completed_with_errors: 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-400',
  failed: 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-400',
}

export function ImportBatchRow({ initial }: { initial: ImportBatch }) {
  const { data: batch } = useQuery({
    queryKey: ['import', initial.id],
    queryFn: () => fetchImportBatch(initial.id),
    initialData: initial,
    refetchInterval: (query) => {
      const status = query.state.data?.status
      return status === 'pending' || status === 'processing' ? 1000 : false
    },
  })

  if (!batch) return null

  return (
    <div className="rounded-lg border border-neutral-200 p-4 dark:border-neutral-800">
      <div className="flex items-center justify-between">
        <p className="font-medium">{batch.filename}</p>
        <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${STATUS_COLOR[batch.status]}`}>
          {STATUS_LABEL[batch.status]}
        </span>
      </div>
      <div className="mt-2 h-2 w-full overflow-hidden rounded-full bg-neutral-100 dark:bg-neutral-800">
        <div
          className="h-full rounded-full bg-neutral-900 transition-all dark:bg-neutral-100"
          style={{ width: `${batch.progressPercent}%` }}
        />
      </div>
      <p className="mt-1 text-xs text-neutral-500 dark:text-neutral-400">
        {batch.rowsImported} importée(s) · {batch.rowsSkipped} ignorée(s) · {batch.rowsFailed} échouée(s) / {batch.rowsTotal} lignes
      </p>
      {batch.errorsSample.length > 0 && (
        <details className="mt-2 text-xs">
          <summary className="cursor-pointer text-red-600 dark:text-red-400">
            Voir {batch.errorsSample.length} erreur(s)
          </summary>
          <ul className="mt-1 list-disc space-y-0.5 pl-4 text-neutral-500 dark:text-neutral-400">
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
