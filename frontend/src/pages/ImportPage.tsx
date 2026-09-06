import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useRef, useState } from 'react'
import { RefreshCw, UploadCloud } from 'lucide-react'
import { fetchImportBatches, uploadLetterboxdExport } from '@/services/importService'
import { fetchSyncState, triggerSync, updateSyncSettings } from '@/services/syncService'
import { ImportBatchRow } from '@/components/ImportBatchRow'
import { ErrorState } from '@/components/ErrorState'
import { Button } from '@/components/ui/Button'
import { Badge } from '@/components/ui/Badge'
import { TextField } from '@/components/ui/TextField'
import { apiErrorMessage, apiFieldErrors } from '@/utils/apiError'
import type { ImportBatch, SyncState } from '@/types/api'
import { cn } from '@/utils/cn'
import { PageMeta } from '@/components/PageMeta'
import { SkeletonImportHistory, SkeletonSyncPanel } from '@/components/Skeleton'

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
      <PageMeta title="Import" />
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
        <div className="flex flex-wrap items-baseline justify-between gap-2">
          <h2 className="font-serif text-2xl font-bold">Historique des imports</h2>
          {history.data && history.data.length > 0 && (
            <span className="font-mono text-[11px] uppercase tracking-widest text-subtle">
              {history.data.length} import{history.data.length > 1 ? 's' : ''}
            </span>
          )}
        </div>
        {history.isLoading && <SkeletonImportHistory />}
        {/* There was no error branch here either: a failed fetch left the heading standing
            over nothing at all, indistinguishable from an account that has never imported. */}
        {history.isError && <ErrorState message={(history.error as Error).message} />}
        {history.data && history.data.length === 0 && <p className="text-sm text-subtle">Aucun import pour l'instant.</p>}
        {history.data && history.data.length > 0 && <ImportHistoryList batches={history.data} />}
      </section>

      <LetterboxdRssSyncSection />
    </div>
  )
}

/** Rows kept on screen before the list starts scrolling. */
const VISIBLE_BATCHES = 5

/**
 * The import history, capped at five rows and scrolled past that.
 *
 * Thirty-eight imports pushed the RSS panel a full screen below the fold and turned the page
 * into a log. The cap is a max-height rather than a slice: every import stays reachable, it
 * just stops being the whole page.
 *
 * The height is five collapsed rows plus the gaps between them, which is a measurement and
 * not a round number — hence the arithmetic below rather than a bare value. A row whose
 * error list is unfolded is taller than that, so the fit is approximate in exactly the case
 * where it should be: opening errors gives you more to read, and more to scroll.
 *
 * Short histories get no scroll container at all. Wrapping four rows in one would add a
 * frame around something that was never too long.
 */
function ImportHistoryList({ batches }: { batches: ImportBatch[] }) {
  if (batches.length <= VISIBLE_BATCHES) {
    return (
      <div className="flex flex-col gap-3">
        {batches.map((batch) => (
          <ImportBatchRow key={batch.id} initial={batch} />
        ))}
      </div>
    )
  }

  return (
    <div
      // pr-2 keeps the scrollbar off the rows' right border rather than on top of it.
      className="flex flex-col gap-3 overflow-y-auto pr-2"
      style={{ maxHeight: `calc(${VISIBLE_BATCHES} * ${COLLAPSED_ROW_HEIGHT} + ${VISIBLE_BATCHES - 1} * ${ROW_GAP})` }}
      tabIndex={0}
      role="group"
      aria-label={`Historique des imports, ${batches.length} entrées`}
    >
      {batches.map((batch) => (
        <ImportBatchRow key={batch.id} initial={batch} />
      ))}
    </div>
  )
}

/** One collapsed ImportBatchRow: 1rem padding twice, three lines, two borders. */
const COLLAPSED_ROW_HEIGHT = '6rem'

/** Tailwind's gap-3, as used by the list above. */
const ROW_GAP = '0.75rem'

function LetterboxdRssSyncSection() {
  const queryClient = useQueryClient()

  const { data: syncState, isLoading } = useQuery({ queryKey: ['sync', 'letterboxd'], queryFn: fetchSyncState })

  const trigger = useMutation({
    mutationFn: triggerSync,
    onSuccess: (data) => {
      queryClient.setQueryData(['sync', 'letterboxd'], data)
      // The actual sync runs asynchronously in the worker — refresh shortly after
      // dispatching so the result (success/failure, count) shows up without a manual reload.
      setTimeout(() => queryClient.invalidateQueries({ queryKey: ['sync', 'letterboxd'] }), 4000)
    },
  })

  // Returning null while loading made the whole panel pop in at the bottom of the page,
  // shifting everything above it once the request landed.
  if (isLoading) return <SkeletonSyncPanel />
  if (!syncState) return null

  return (
    <section className="border border-ink p-5 sm:p-6">
      <div className="flex flex-wrap items-center justify-between gap-4">
        <div>
          <h2 className="font-serif text-2xl font-bold">Synchronisation RSS</h2>
          {syncState.configured ? (
            <>
              <p className="mt-1 font-mono text-xs text-subtle">
                @{syncState.username} &middot; {syncState.autoSyncEnabled ? 'automatique (toutes les heures)' : 'manuelle uniquement'}
              </p>
              <p className="mt-1 max-w-md font-body text-xs text-subtle">
                Ne remonte que les films <em>loggés au journal</em> Letterboxd (avec une date). Une note ajoutée via les étoiles
                rapides, sans passer par le journal, n'apparaît jamais dans ce flux &mdash; réimporte le CSV pour celles-là.
              </p>
            </>
          ) : (
            <p className="mt-1 max-w-md font-body text-sm text-subtle">
              Renseigne ton pseudo Letterboxd pour que Mova aille lire ton journal. Le réglage appartient à ton compte :
              chaque profil synchronise le sien.
            </p>
          )}
        </div>
        {syncState.configured && (
          <Button variant="secondary" size="sm" onClick={() => trigger.mutate()} disabled={trigger.isPending}>
            <RefreshCw className={cn('h-3.5 w-3.5', trigger.isPending && 'animate-spin')} strokeWidth={1.5} />
            {trigger.isPending ? 'Synchro en cours' : 'Synchroniser maintenant'}
          </Button>
        )}
      </div>

      {syncState.configured && (
        <div className="mt-4 flex flex-wrap items-center gap-3">
          {syncState.lastSyncedAt ? (
            <>
              <Badge variant={syncState.lastSyncStatus === 'failed' ? 'accent' : 'solid'}>
                {syncState.lastSyncStatus === 'failed' ? 'Échec' : 'OK'}
              </Badge>
              <span className="font-mono text-xs text-subtle">
                Dernière synchro : {new Date(syncState.lastSyncedAt).toLocaleString('fr-FR')}
                {syncState.lastSyncStatus !== 'failed' &&
                  ` · ${syncState.lastRunWatchesImported} nouveau${syncState.lastRunWatchesImported > 1 ? 'x' : ''} visionnage${syncState.lastRunWatchesImported > 1 ? 's' : ''}`}
              </span>
            </>
          ) : (
            <span className="font-mono text-xs text-subtle">Jamais synchronisé pour l'instant.</span>
          )}
        </div>
      )}
      {syncState.lastSyncStatus === 'failed' && syncState.lastSyncError && (
        <p className="mt-2 font-mono text-xs text-accent">{syncState.lastSyncError}</p>
      )}

      <SyncSettingsForm state={syncState} />
    </section>
  )
}

/**
 * Where the Letterboxd account behind the sync is actually set.
 *
 * There was nowhere. The setting moved off the installation's configuration and onto each
 * user's row when the app went multi-user, but nothing was ever built to write it: the
 * migration seeded it once and that was the last word on the subject. A second account could
 * not sync at all, and this panel went on pointing people at a server-side file that had
 * stopped having any effect on it.
 *
 * Folded away once configured. An account that already syncs does not need a form standing
 * open under it — the button that opens this one is the rarely-used path, and the
 * "Synchroniser maintenant" button above is the common one.
 */
function SyncSettingsForm({ state }: { state: SyncState }) {
  const queryClient = useQueryClient()

  // Open by default when there is nothing configured: the panel would otherwise say what is
  // missing and hide the only control that fixes it.
  const [isOpen, setIsOpen] = useState(!state.configured)
  const [username, setUsername] = useState(state.username ?? '')
  const [autoSync, setAutoSync] = useState(state.autoSyncEnabled)
  const [error, setError] = useState<string | null>(null)
  const [fieldError, setFieldError] = useState<string | undefined>(undefined)

  const save = useMutation({
    mutationFn: updateSyncSettings,
    onSuccess: (data) => {
      queryClient.setQueryData(['sync', 'letterboxd'], data)
      setIsOpen(false)
      setError(null)
      setFieldError(undefined)
    },
    onError: (err) => {
      setFieldError(apiFieldErrors(err).letterboxdUsername)
      setError(apiFieldErrors(err).letterboxdUsername ? null : apiErrorMessage(err, "L'enregistrement a échoué."))
    },
  })

  if (!isOpen) {
    return (
      <button
        type="button"
        onClick={() => setIsOpen(true)}
        className="mt-4 font-mono text-xs uppercase tracking-widest text-accent underline decoration-2 underline-offset-4 hover:no-underline"
      >
        Changer de compte Letterboxd
      </button>
    )
  }

  return (
    <form
      onSubmit={(event) => {
        event.preventDefault()
        const trimmed = username.trim()
        save.mutate({ letterboxdUsername: trimmed === '' ? null : trimmed, rssSyncEnabled: autoSync })
      }}
      className="mt-5 flex flex-col gap-4 border-t border-ink/20 pt-5"
    >
      <TextField
        label="Pseudo Letterboxd"
        value={username}
        onChange={(event) => setUsername(event.target.value)}
        placeholder="tonpseudo"
        autoComplete="off"
        spellCheck={false}
        error={fieldError}
        hint="Celui de l'adresse letterboxd.com/tonpseudo/. Laisse vide pour arrêter la synchro."
        className="sm:max-w-xs"
      />

      <label className="flex max-w-md items-start gap-3">
        <input
          type="checkbox"
          checked={autoSync}
          onChange={(event) => setAutoSync(event.target.checked)}
          className="mt-0.5 h-4 w-4 shrink-0 accent-accent"
        />
        <span className="font-body text-sm">
          Synchroniser automatiquement, toutes les heures
          <span className="mt-0.5 block font-mono text-[10px] text-subtle">
            {/* Said out loud because the delay is real and would otherwise read as the
                setting not having been saved. The scheduler is built once per worker start,
                and the worker recycles hourly. */}
            Prise en compte au prochain redémarrage du worker, au plus tard dans l'heure
          </span>
        </span>
      </label>

      {error && <p className="font-mono text-xs text-accent">{error}</p>}

      <div className="flex flex-wrap items-center gap-3">
        <Button type="submit" size="sm" disabled={save.isPending}>
          {save.isPending ? 'Enregistrement…' : 'Enregistrer'}
        </Button>
        {state.configured && (
          <button
            type="button"
            onClick={() => {
              setIsOpen(false)
              setUsername(state.username ?? '')
              setAutoSync(state.autoSyncEnabled)
              setError(null)
              setFieldError(undefined)
            }}
            className="font-mono text-xs uppercase tracking-widest text-subtle hover:text-ink"
          >
            Annuler
          </button>
        )}
      </div>
    </form>
  )
}
