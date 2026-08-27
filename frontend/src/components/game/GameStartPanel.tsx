import { Dices } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import type { GameMode } from '@/types/api'

/** Shown when no run exists yet. Starting one is always an explicit act, never a side effect. */
export function GameStartPanel({
  mode,
  onStart,
  isPending,
}: {
  mode: GameMode
  onStart: () => void
  isPending: boolean
}) {
  return (
    <div className="flex flex-col items-center gap-4 border border-dashed border-ink/40 py-20 text-center">
      <p className="font-serif text-2xl font-bold">Prêt·e ?</p>
      <p className="max-w-md font-body text-sm text-subtle">
        {mode === 'daily'
          ? "Le film du jour t'attend. Une seule partie, jusqu'à minuit."
          : 'Un film au hasard parmi ceux que tu as vus.'}
      </p>
      <Button onClick={onStart} disabled={isPending}>
        <Dices className="h-4 w-4" strokeWidth={2} />
        {isPending ? 'Tirage…' : 'Jouer'}
      </Button>
    </div>
  )
}
