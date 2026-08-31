import { Flag } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { apiErrorMessage } from '@/utils/apiError'
import type { GameMode } from '@/types/api'

/**
 * "Je donne ma langue au chat" — offered by all eight games, in the infinite mode only.
 *
 * The daily board has nothing to move on to until midnight, so giving up there would end
 * the day rather than end a run; the API has no such route and this draws nothing. Returning
 * null rather than making every page test the mode keeps all eight call sites one line long.
 *
 * Deliberately quiet and set apart from the move above it: ghost rather than a filled
 * button, and its own row. It is a way out, not the thing to do next.
 */
export function RevealAnswer({
  mode,
  onReveal,
  isPending,
  error,
}: {
  mode: GameMode
  onReveal: () => void
  isPending: boolean
  error: unknown
}) {
  if (mode !== 'infinite') {
    return null
  }

  return (
    <div className="flex flex-col items-start gap-2">
      <Button variant="ghost" size="sm" onClick={onReveal} disabled={isPending}>
        <Flag className="h-4 w-4" strokeWidth={2} />
        {isPending ? 'On révèle…' : 'Donner la réponse'}
      </Button>
      {/* No confirmation step: in the infinite mode a run costs one click to replace, so a
          dialog guarding it would be more expensive than the mistake it prevents. */}
      <p className="font-mono text-[10px] uppercase tracking-widest text-faint">
        La partie s'arrête et la réponse s'affiche
      </p>
      {error != null && (
        <p className="font-mono text-xs text-accent">{apiErrorMessage(error, 'Impossible de révéler la réponse.')}</p>
      )}
    </div>
  )
}
