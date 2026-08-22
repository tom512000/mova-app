import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useRef, useState } from 'react'
import { fetchImportBatches, uploadLetterboxdExport } from '@/services/importService'
import { ImportBatchRow } from '@/components/ImportBatchRow'
import { ErrorState } from '@/components/ErrorState'
import type { ImportBatch } from '@/types/api'

export function ImportPage() {
  const [dragOver, setDragOver] = useState(false)
  const [sessionBatches, setSessionBatches] = useState<ImportBatch[]>([])
  const [unsupported, setUnsupported] = useState<string[]>([])
  const fileInputRef = useRef<HTMLInputElement>(null)
  const queryClient = useQueryClient()

  const history = useQuery({ queryKey: ['import', 'history'], queryFn: fetchImportBatches })

  const upload = useMutation({
    mutationFn: uploadLetterboxdExport,
    onSuccess: (data) => {
      setSessionBatches(data.batches)
      setUnsupported(data.unsupportedFiles)
      queryClient.invalidateQueries({ queryKey: ['import', 'history'] })
    },
  })

  function handleFile(file: File | undefined) {
    if (!file) return
    upload.mutate(file)
  }

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h1 className="text-2xl font-semibold tracking-tight">Import Letterboxd</h1>
        <p className="text-sm text-neutral-500 dark:text-neutral-400">
          Dépose l'export .zip de ton compte Letterboxd (Réglages → Importer &amp; exporter), ou un fichier .csv individuel.
        </p>
      </div>

      <div
        onDragOver={(e) => {
          e.preventDefault()
          setDragOver(true)
        }}
        onDragLeave={() => setDragOver(false)}
        onDrop={(e) => {
          e.preventDefault()
          setDragOver(false)
          handleFile(e.dataTransfer.files[0])
        }}
        onClick={() => fileInputRef.current?.click()}
        className={`flex cursor-pointer flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed p-12 text-center transition-colors ${
          dragOver ? 'border-neutral-500 bg-neutral-100 dark:bg-neutral-800' : 'border-neutral-300 dark:border-neutral-700'
        }`}
      >
        <p className="font-medium">Glisse ton fichier ici, ou clique pour parcourir</p>
        <p className="text-xs text-neutral-500 dark:text-neutral-400">Formats acceptés : .zip, .csv (max 100 Mo)</p>
        <input
          ref={fileInputRef}
          type="file"
          accept=".zip,.csv"
          className="hidden"
          onChange={(e) => handleFile(e.target.files?.[0])}
        />
      </div>

      {upload.isPending && <p className="text-sm text-neutral-500">Envoi en cours...</p>}
      {upload.isError && <ErrorState message={(upload.error as Error).message} />}

      {unsupported.length > 0 && (
        <div className="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800 dark:border-amber-900/50 dark:bg-amber-950/30 dark:text-amber-400">
          Fichiers ignorés (pas encore pris en charge) : {unsupported.join(', ')}
        </div>
      )}

      {sessionBatches.length > 0 && (
        <section className="flex flex-col gap-3">
          <h2 className="text-lg font-medium">Import en cours</h2>
          {sessionBatches.map((batch) => (
            <ImportBatchRow key={batch.id} initial={batch} />
          ))}
        </section>
      )}

      <section className="flex flex-col gap-3">
        <h2 className="text-lg font-medium">Historique des imports</h2>
        {history.data && history.data.length === 0 && (
          <p className="text-sm text-neutral-500 dark:text-neutral-400">Aucun import pour l'instant.</p>
        )}
        {history.data?.map((batch) => <ImportBatchRow key={batch.id} initial={batch} />)}
      </section>
    </div>
  )
}
