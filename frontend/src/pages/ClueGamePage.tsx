import { useParams } from 'react-router-dom'
import { useFilmGame } from '@/hooks/useFilmGame'
import { GameHeader } from '@/components/game/GameHeader'
import { GameStartPanel } from '@/components/game/GameStartPanel'
import { GameOutcome } from '@/components/game/GameOutcome'
import { GuessInput } from '@/components/game/GuessInput'
import { RevealAnswer } from '@/components/game/RevealAnswer'
import { GuessList } from '@/components/game/GuessList'
import { Skeleton } from '@/components/Skeleton'
import { ErrorState } from '@/components/ErrorState'
import { apiErrorMessage } from '@/utils/apiError'
import type { GameMode, GameState } from '@/types/api'

export function ClueGamePage() {
  const { mode } = useParams<{ mode: string }>()
  const gameMode: GameMode = mode === 'infinite' ? 'infinite' : 'daily'
  const { session, isLoading, isError, error, start, reveal, guess, isOver } = useFilmGame('clue', gameMode)

  return (
    <div className="flex flex-col gap-8">
      <GameHeader
        game="clue"
        mode={gameMode}
        title="Le film mystère"
        rules="Un film que tu as vu se cache derrière les indices. Chaque proposition ratée en dévoile un de plus, du plus vague au plus évident."
        puzzleDate={session?.puzzleDate}
      />

      {isLoading && <Skeleton className="h-64 w-full" />}
      {isError && <ErrorState message={(error as Error).message} />}
      {start.isError && <ErrorState message={apiErrorMessage(start.error, 'Impossible de lancer la partie.')} />}

      {session === null && !isLoading && (
        <GameStartPanel mode={gameMode} onStart={() => start.mutate()} isPending={start.isPending} />
      )}

      {session && (
        <>
          <section className="border border-ink p-5 sm:p-6">
            <div className="mb-4 flex flex-wrap items-baseline justify-between gap-2">
              <h2 className="font-serif text-2xl font-bold">Indices</h2>
              <span className="font-mono text-xs uppercase tracking-widest text-subtle">
                {session.clues.length} / {session.maxAttempts} dévoilé{session.clues.length > 1 ? 's' : ''}
              </span>
            </div>
            <ClueLadder state={session} />
          </section>

          {!isOver && (
            <>
              <GuessInput
                state={session}
                onGuess={(movieId) => guess.mutate(movieId)}
                isPending={guess.isPending}
                error={guess.isError ? guess.error : null}
              />
              <RevealAnswer
                mode={gameMode}
                onReveal={() => reveal.mutate()}
                isPending={reveal.isPending}
                error={reveal.isError ? reveal.error : null}
              />
            </>
          )}

          {isOver && (
            <GameOutcome state={session} onReplay={() => start.mutate()} isReplaying={start.isPending} />
          )}

          {session.guesses.length > 0 && (
            <section className="border border-ink p-5 sm:p-6">
              <h2 className="mb-4 font-serif text-2xl font-bold">Propositions ({session.guesses.length})</h2>
              <GuessList guesses={session.guesses} />
            </section>
          )}
        </>
      )}
    </div>
  )
}

/**
 * Every rung is drawn, unlocked or not, so the length of the run is visible from the start
 * and each wrong guess reads as progress rather than as a penalty.
 */
function ClueLadder({ state }: { state: GameState }) {
  return (
    <ol className="flex flex-col divide-y divide-ink/15">
      {Array.from({ length: state.maxAttempts }, (_, index) => {
        const clue = state.clues[index]

        return (
          <li key={index} className="flex flex-col gap-0.5 py-3 first:pt-0 last:pb-0 sm:flex-row sm:items-baseline sm:gap-4">
            <span className="w-6 shrink-0 font-mono text-[10px] text-subtle">
              {String(index + 1).padStart(2, '0')}
            </span>
            {clue ? (
              <>
                <span className="w-44 shrink-0 font-mono text-[10px] uppercase tracking-widest text-subtle">
                  {clue.label}
                </span>
                <span className="font-serif text-lg font-bold">{clue.value}</span>
              </>
            ) : (
              <span className="font-mono text-xs uppercase tracking-widest text-faint">Verrouillé</span>
            )}
          </li>
        )
      })}
    </ol>
  )
}
