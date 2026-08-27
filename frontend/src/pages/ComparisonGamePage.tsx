import { useParams } from 'react-router-dom'
import { useFilmGame } from '@/hooks/useFilmGame'
import { GameHeader } from '@/components/game/GameHeader'
import { GameStartPanel } from '@/components/game/GameStartPanel'
import { GameOutcome } from '@/components/game/GameOutcome'
import { GuessInput } from '@/components/game/GuessInput'
import { ComparisonCard, ComparisonLegend } from '@/components/game/ComparisonCard'
import { Skeleton } from '@/components/Skeleton'
import { ErrorState } from '@/components/ErrorState'
import { apiErrorMessage } from '@/utils/apiError'
import type { GameMode } from '@/types/api'

export function ComparisonGamePage() {
  const { mode } = useParams<{ mode: string }>()
  const gameMode: GameMode = mode === 'infinite' ? 'infinite' : 'daily'
  const { session, isLoading, isError, error, start, guess, isOver } = useFilmGame('compare', gameMode)

  // Newest first: the card you just earned is the one you are reading.
  const guesses = session ? [...session.guesses].reverse() : []

  return (
    <div className="flex flex-col gap-8">
      <GameHeader
        game="compare"
        mode={gameMode}
        title="Le film comparé"
        rules="Propose un film que tu as vu : chacun de ses attributs se colore selon sa distance au film cherché. À toi de refermer l'écart."
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
          {!isOver && (
            <GuessInput
              state={session}
              onGuess={(movieId) => guess.mutate(movieId)}
              isPending={guess.isPending}
              error={guess.isError ? guess.error : null}
            />
          )}

          {isOver && (
            <GameOutcome state={session} onReplay={() => start.mutate()} isReplaying={start.isPending} />
          )}

          <section className="flex flex-col gap-4">
            <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-2 border-b border-ink/20 pb-3">
              <h2 className="font-serif text-2xl font-bold">
                Comparaisons ({session.guesses.length}/{session.maxAttempts})
              </h2>
              <ComparisonLegend />
            </div>

            {guesses.length === 0 ? (
              <p className="border border-dashed border-ink/40 py-12 text-center font-body text-sm text-subtle">
                Aucune proposition pour l'instant. Le premier film que tu nommes ouvrira le jeu.
              </p>
            ) : (
              <ol className="flex flex-col gap-3">
                {guesses.map((entry) => (
                  <li key={entry.movieId}>
                    <ComparisonCard
                      guess={entry}
                      // Numbered in play order even though they are listed newest first.
                      attempt={session.guesses.findIndex((other) => other.movieId === entry.movieId) + 1}
                    />
                  </li>
                ))}
              </ol>
            )}
          </section>
        </>
      )}
    </div>
  )
}
