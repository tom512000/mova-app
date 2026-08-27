import { useParams } from 'react-router-dom'
import { useFilmGame } from '@/hooks/useFilmGame'
import { GameHeader } from '@/components/game/GameHeader'
import { GameStartPanel } from '@/components/game/GameStartPanel'
import { GameOutcome } from '@/components/game/GameOutcome'
import { GuessInput } from '@/components/game/GuessInput'
import { GuessList } from '@/components/game/GuessList'
import { PixelPoster } from '@/components/game/PixelPoster'
import { Skeleton } from '@/components/Skeleton'
import { ErrorState } from '@/components/ErrorState'
import { apiErrorMessage } from '@/utils/apiError'
import { cn } from '@/utils/cn'
import type { GameMode, GameState } from '@/types/api'

export function PosterGamePage() {
  const { mode } = useParams<{ mode: string }>()
  const gameMode: GameMode = mode === 'infinite' ? 'infinite' : 'daily'
  const { session, isLoading, isError, error, start, guess, isOver } = useFilmGame('poster', gameMode)

  return (
    <div className="flex flex-col gap-8">
      <GameHeader
        game="poster"
        mode={gameMode}
        title="Le film pixelisé"
        rules="Une affiche, vue de bien trop loin. Chaque proposition la rapproche d'un cran — à toi de la reconnaître avant qu'il ne reste plus de crans."
        puzzleDate={session?.puzzleDate}
      />

      {isLoading && <Skeleton className="h-96 w-full" />}
      {isError && <ErrorState message={(error as Error).message} />}
      {start.isError && <ErrorState message={apiErrorMessage(start.error, 'Impossible de lancer la partie.')} />}

      {session === null && !isLoading && (
        <GameStartPanel mode={gameMode} onStart={() => start.mutate()} isPending={start.isPending} />
      )}

      {session && (
        <>
          {/* Once the run is over the reveal below shows the real poster, full size and in
              focus. Leaving the pixelated one above it would be showing the same artwork
              twice, worse first. */}
          {!isOver && <PosterBoard state={session} />}

          {!isOver && (
            <GuessInput
              state={session}
              onGuess={(movieId) => guess.mutate(movieId)}
              isPending={guess.isPending}
              error={guess.isError ? guess.error : null}
            />
          )}

          {isOver && <GameOutcome state={session} onReplay={() => start.mutate()} isReplaying={start.isPending} />}

          {session.guesses.length > 0 && (
            <section className="border border-ink p-5 sm:p-6">
              <h2 className="mb-4 font-serif text-2xl font-bold">Propositions ({session.guesses.length})</h2>
              <GuessList guesses={session.guesses} tone="colour" />
            </section>
          )}
        </>
      )}
    </div>
  )
}

/** The affiche itself, framed, with the sharpness ladder underneath it. */
function PosterBoard({ state }: { state: GameState }) {
  const poster = state.poster

  return (
    <section className="flex flex-col items-center gap-4 border border-ink p-5 sm:p-6">
      <div className="w-full max-w-[18rem] border-4 border-ink bg-surface-2">
        {poster ? (
          <PixelPoster pixels={poster} />
        ) : (
          // The answer was drawn for its poster, so this only happens if TMDB drops the
          // artwork mid-run. Saying so beats an empty frame the player reads as a clue.
          <p className="flex aspect-2/3 items-center justify-center px-4 text-center font-body text-sm text-subtle">
            L'affiche est introuvable chez TMDB pour l'instant.
          </p>
        )}
      </div>

      {poster && <SharpnessLadder step={poster.step} steps={poster.steps} width={poster.width} />}
    </section>
  )
}

/**
 * How far up the ladder the poster has climbed. Drawn in full from the start, like the clue
 * game's rungs, so the length of the run is visible before the first guess is spent.
 */
function SharpnessLadder({ step, steps, width }: { step: number; steps: number; width: number }) {
  return (
    <div className="flex flex-col items-center gap-2">
      <div className="flex gap-1" role="presentation">
        {Array.from({ length: steps }, (_, index) => (
          <span
            key={index}
            className={cn('h-1.5 w-8 border border-ink', index < step ? 'bg-ink' : 'bg-transparent')}
          />
        ))}
      </div>
      <p className="font-mono text-[10px] uppercase tracking-widest text-subtle">
        Netteté {step} / {steps} &middot; {width} pixels de large
      </p>
    </div>
  )
}
