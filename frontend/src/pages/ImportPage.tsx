import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useRef, useState } from 'react'
import { UploadCloud } from 'lucide-react'
import { fetchImportBatches, uploadLetterboxdExport } from '@/services/importService'
import { ImportBatchRow } from '@/components/ImportBatchRow'
import { ErrorState } from '@/components/ErrorState'
import type { ImportBatch } from '@/types/api'
import { cn } from '@/utils/cn'

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
    <div className="flex flex-col gap-8">
      <div className="border-b-4 border-ink pb-6">
        <h1 className="font-serif text-5xl font-black tracking-tighter sm:text-6xl">Import</h1>
        <p className="mt-2 max-w-xl font-body text-sm text-subtle">
          Dépose l'export .zip de ton compte Letterboxd (Réglages &rarr; Importer &amp; exporter), ou un fichier .csv individuel.
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
        className={cn(
          'flex cursor-pointer flex-col items-center justify-center gap-3 border-2 border-dashed p-16 text-center transition-colors duration-200',
          dragOver ? 'border-accent bg-accent/5' : 'border-ink/40 hover:border-ink'
        )}
      >
        <UploadCloud className="h-8 w-8 text-subtle" strokeWidth={1.5} />
        <p className="font-serif text-lg font-bold">Glisse ton fichier ici, ou clique pour parcourir</p>
        <p className="font-mono text-[11px] uppercase tracking-widest text-subtle">Formats acceptés : .zip, .csv (max 100 Mo)</p>
        <input ref={fileInputRef} type="file" accept=".zip,.csv" className="hidden" onChange={(e) => handleFile(e.target.files?.[0])} />
      </div>

      {upload.isPending && <p className="font-mono text-xs uppercase tracking-widest text-subtle">Envoi en cours&hellip;</p>}
      {upload.isError && <ErrorState message={(upload.error as Error).message} />}

      {unsupported.length > 0 && (
        <div className="border border-accent bg-accent/5 p-3 font-mono text-xs text-accent">
          Fichiers ignorés (pas encore pris en charge) : {unsupported.join(', ')}
        </div>
      )}

      {sessionBatches.length > 0 && (
        <section className="flex flex-col gap-3">
          <h2 className="font-serif text-2xl font-bold">Import en cours</h2>
          {sessionBatches.map((batch) => (
            <ImportBatchRow key={batch.id} initial={batch} />
          ))}
        </section>
      )}

      <section className="flex flex-col gap-3">
        <h2 className="font-serif text-2xl font-bold">Historique des imports</h2>
        {history.data && history.data.length === 0 && <p className="text-sm text-subtle">Aucun import pour l'instant.</p>}
        {history.data?.map((batch) => <ImportBatchRow key={batch.id} initial={batch} />)}
      </section>
    </div>
  )
}
