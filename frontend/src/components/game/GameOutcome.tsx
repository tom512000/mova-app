import { Link } from 'react-router-dom'
import { Dices } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { Badge } from '@/components/ui/Badge'
import { cn } from '@/utils/cn'
import type { GameState } from '@/types/api'

/** The verdict, the answer, and the way back in. Inverted to ink when the run was won. */
export function GameOutcome({
  state,
  onReplay,
  isReplaying,
}: {
  state: GameState
  onReplay: () => void
  isReplaying: boolean
}) {
  const won = state.status === 'won'
  // Asking for the answer is not the same as running out of tries, and the verdict says so
  // rather than filing it under "Raté": nothing was got wrong, the run was stopped.
  const givenUp = state.status === 'revealed'
  const answer = state.answer

  return (
    <section className={cn('border p-5 sm:p-6', won ? 'border-ink bg-ink text-paper' : 'border-ink')}>
      <div className="flex flex-col gap-5 sm:flex-row sm:items-center">
        {answer?.posterUrl && (
          <img src={answer.posterUrl} alt="" className="h-40 w-28 shrink-0 border border-current object-cover" />
        )}
        <div className="min-w-0 flex-1">
          {/* Accent is reserved for a run that was got wrong. Giving up is neither a win
              nor a miss, so it stays in the neutral outline. */}
          <Badge
            variant={won || givenUp ? 'outline' : 'accent'}
            className={won ? 'border-paper/50 text-paper' : undefined}
          >
            {won ? 'Trouvé' : givenUp ? 'Réponse donnée' : 'Raté'}
          </Badge>
          <p className="mt-2 font-serif text-3xl font-black leading-tight">
            {answer ? answer.title : 'Film inconnu'}{' '}
            {answer?.releaseYear && <span className="font-normal opacity-70">({answer.releaseYear})</span>}
          </p>
          <p className="mt-1 font-mono text-xs uppercase tracking-widest opacity-70">
            {won && `En ${state.attemptsUsed} essai${state.attemptsUsed > 1 ? 's' : ''}`}
            {givenUp &&
              (state.attemptsUsed > 0
                ? `Arrêt après ${state.attemptsUsed} essai${state.attemptsUsed > 1 ? 's' : ''}`
                : 'Arrêt sans avoir proposé de film')}
            {!won && !givenUp && `${state.attemptsUsed} essais, aucun bon`}
          </p>

          <div className="mt-4 flex flex-wrap items-center gap-3">
            {answer && (
              <Link
                to={`/movies/${answer.id}`}
                className="font-mono text-xs uppercase tracking-widest underline decoration-accent decoration-2 underline-offset-4"
              >
                Voir la fiche
              </Link>
            )}
            {state.mode === 'infinite' ? (
              <Button
                variant={won ? 'secondary' : 'primary'}
                size="sm"
                onClick={onReplay}
                disabled={isReplaying}
                className={won ? 'border-paper text-paper hover:bg-paper hover:text-ink' : undefined}
              >
                <Dices className="h-4 w-4" strokeWidth={2} />
                {isReplaying ? 'Tirage…' : 'Nouvelle partie'}
              </Button>
            ) : (
              <Link
                to={`/games/${state.game}/infinite`}
                className="font-mono text-xs uppercase tracking-widest opacity-70 hover:opacity-100"
              >
                Reviens demain — ou enchaîne en mode infini &rarr;
              </Link>
            )}
          </div>
        </div>
      </div>
    </section>
  )
}
