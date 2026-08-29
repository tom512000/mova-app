import { Dices } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import type { GameMode } from '@/types/api'

/**
 * Shown when no run exists yet. Starting one is always an explicit act, never a side effect.
 *
 * The two lines default to a film being drawn, which is what six of the eight games do. The
 * other two draw a pair and a set of five, and say so — a panel promising "un film au
 * hasard" before dealing two of them is a small lie that makes the first board confusing.
 */
export function GameStartPanel({
  mode,
  onStart,
  isPending,
  daily = "Le film du jour t'attend. Une seule partie, jusqu'à minuit.",
  infinite = 'Un film au hasard parmi ceux que tu as vus.',
}: {
  mode: GameMode
  onStart: () => void
  isPending: boolean
  daily?: string
  infinite?: string
}) {
  return (
    <div className="flex flex-col items-center gap-4 border border-dashed border-ink/40 py-20 text-center">
      <p className="font-serif text-2xl font-bold">Prêt·e ?</p>
      <p className="max-w-md font-body text-sm text-subtle">{mode === 'daily' ? daily : infinite}</p>
      <Button onClick={onStart} disabled={isPending}>
        <Dices className="h-4 w-4" strokeWidth={2} />
        {isPending ? 'Tirage…' : 'Jouer'}
      </Button>
    </div>
  )
}
