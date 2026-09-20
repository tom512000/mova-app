import { useEffect, useRef } from 'react'
import { X } from 'lucide-react'
import type { Id } from '@/types/api'
import { CardDetailPanel } from '@/components/cards/CardDetailPanel'

/**
 * A card, looked at without leaving the page.
 *
 * Clicking a card in a grid is a glance, not a destination — a full navigation for a panel
 * of six figures throws away the scroll position and the filters somebody just set, and
 * makes going back the price of every look. The card's URL still works for anyone who
 * arrives on it directly; both render the same panel.
 *
 * The native `<dialog>` with showModal(), as ShareProfileDialog already uses here: the focus
 * trap, the backdrop and Escape-to-close come with the element rather than being rebuilt.
 */
export function CardDialog({ cardId, onClose }: { cardId: Id; onClose: () => void }) {
  const dialogRef = useRef<HTMLDialogElement>(null)

  useEffect(() => {
    dialogRef.current?.showModal()
  }, [])

  return (
    <dialog
      ref={dialogRef}
      onClose={onClose}
      onClick={(event) => {
        // The dialog element covers the whole viewport; a click landing on it rather than on
        // the panel inside is a backdrop click.
        if (event.target === dialogRef.current) dialogRef.current?.close()
      }}
      className="m-auto w-[min(46rem,calc(100vw-2rem))] border-2 border-ink bg-paper p-0 text-ink backdrop:bg-ink/50"
    >
      <div className="flex items-center justify-between border-b border-ink px-5 py-3">
        <h2 className="font-mono text-[10px] uppercase tracking-widest text-subtle">La carte</h2>
        <button
          onClick={() => dialogRef.current?.close()}
          aria-label="Fermer"
          className="flex h-8 w-8 items-center justify-center text-subtle hover:text-accent"
        >
          <X className="h-4 w-4" strokeWidth={1.5} />
        </button>
      </div>

      <div className="max-h-[75vh] overflow-y-auto p-5">
        <CardDetailPanel cardId={cardId} />
      </div>
    </dialog>
  )
}
