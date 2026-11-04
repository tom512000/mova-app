import { Badge } from '@/components/ui/Badge'
import type { ImportFileReport, ImportFileStatus, ImportFileType } from '@/types/api'

const FILE_TYPE_LABEL: Record<ImportFileType, string> = {
  diary: 'Journal',
  ratings: 'Notes',
  watched: 'Vus',
  reviews: 'Critiques',
  watchlist: 'Watchlist',
  profile: 'Profil',
  list: 'Liste',
}

/*
 * Groups in the order somebody reads an upload: what was taken, then what could be taken
 * with one more importer, then what the upload never looked at. The first group is folded
 * because the same files reappear right below as live import rows.
 */
const GROUPS: ReadonlyArray<{ status: ImportFileStatus; title: string; open: boolean }> = [
  { status: 'queued', title: 'Importés', open: false },
  { status: 'unsupported', title: 'Pas encore pris en charge', open: true },
  { status: 'ignored', title: 'Non lus', open: true },
]

/**
 * Every file an upload contained, and what became of it.
 *
 * This replaced a red banner naming the root CSVs that no importer matched — which, on a
 * real export, was comments.csv alone, while fourteen other files in likes/, lists/,
 * deleted/ and orphaned/ were dropped before anything could mention them. Not being read is
 * not an error, so nothing here wears the accent: it is an inventory, not a warning.
 */
export function ImportFileInventory({ files }: { files: ImportFileReport[] }) {
  const unread = files.filter((file) => file.status !== 'queued').length

  return (
    <section className="flex flex-col gap-3">
      <div className="flex flex-wrap items-baseline justify-between gap-2">
        <h2 className="font-serif text-2xl font-bold">Contenu de l&rsquo;archive</h2>
        <span className="font-mono text-[11px] uppercase tracking-widest text-subtle">
          {files.length} fichier{files.length > 1 ? 's' : ''} &middot; {files.length - unread} lu
          {files.length - unread > 1 ? 's' : ''} &middot; {unread} non lu{unread > 1 ? 's' : ''}
        </span>
      </div>

      <div className="flex flex-col border border-ink">
        {GROUPS.map(({ status, title, open }) => {
          const group = files.filter((file) => file.status === status)
          if (group.length === 0) return null

          return (
            <details key={status} open={open} className="group border-b border-ink/20 last:border-b-0">
              <summary className="flex cursor-pointer items-baseline justify-between gap-3 px-4 py-3 font-mono text-[11px] uppercase tracking-widest">
                <span>{title}</span>
                <span className="text-subtle">{group.length}</span>
              </summary>
              <ul className="flex flex-col">
                {group.map((file) => (
                  <ImportFileLine key={file.path} file={file} />
                ))}
              </ul>
            </details>
          )
        })}
      </div>
    </section>
  )
}

function ImportFileLine({ file }: { file: ImportFileReport }) {
  return (
    <li className="flex flex-col gap-1 border-t border-ink/10 px-4 py-2.5">
      <div className="flex flex-wrap items-center justify-between gap-x-3 gap-y-1">
        <p className="min-w-0 break-all font-mono text-sm">{file.path}</p>
        <div className="flex shrink-0 items-center gap-2">
          {file.rowCount !== null && (
            <span className="font-mono text-[11px] text-subtle">
              {file.rowCount} ligne{file.rowCount > 1 ? 's' : ''}
            </span>
          )}
          {file.fileType && <Badge variant="solid">{FILE_TYPE_LABEL[file.fileType]}</Badge>}
        </div>
      </div>
      {file.columns.length > 0 && (
        // One line, the full header on hover: the columns are what decides whether a file
        // deserves an importer, but a twelve-column header should not take a paragraph.
        <p className="truncate font-mono text-[11px] text-subtle" title={file.columns.join(', ')}>
          {file.columns.join(' · ')}
        </p>
      )}
      {file.reason && <p className="font-body text-xs text-subtle">{file.reason}</p>}
    </li>
  )
}
