import type { ReactNode } from 'react'
import { useFilmGame } from '@/hooks/useFilmGame'
import { GameHeader } from '@/components/game/GameHeader'
import { GameStartPanel } from '@/components/game/GameStartPanel'
import { GameOutcome } from '@/components/game/GameOutcome'
import { GuessInput } from '@/components/game/GuessInput'
import { RevealAnswer } from '@/components/game/RevealAnswer'
import { GuessList } from '@/components/game/GuessList'
import { PixelArtwork } from '@/components/game/PixelArtwork'
import { Skeleton } from '@/components/Skeleton'
import { ErrorState } from '@/components/ErrorState'
import { apiErrorMessage } from '@/utils/apiError'
import { cn } from '@/utils/cn'
import type { GameKind, GameMode, GameState } from '@/types/api'

/**
 * The board both pixel games are played on.
 *
 * "Le film pixelisé" and "Le décor" differ in exactly three things: which picture is
 * reduced, the shape of the frame it hangs in, and how a missing file should be worded.
 * Everything else — the ladder, the guess box, the reveal — is the same screen, so it is
 * written once here rather than twice under two page names.
 */
export function PixelGame({
  game,
  mode,
  title,
  rules,
  artworkLabel,
  missing,
  frameClassName,
}: {
  game: Extract<GameKind, 'poster' | 'backdrop'>
  mode: GameMode
  title: string
  rules: ReactNode
  /** Read out to screen readers, and the subject of the "missing" line. */
  artworkLabel: string
  missing: string
  /** The empty frame's aspect, so a missing file does not collapse the layout. */
  frameClassName: string
}) {
  const { session, isLoading, isError, error, start, reveal, guess, isOver } = useFilmGame(game, mode)

  return (
    <div className="flex flex-col gap-8">
      <GameHeader game={game} mode={mode} title={title} rules={rules} puzzleDate={session?.puzzleDate} />

      {isLoading && <Skeleton className="h-96 w-full" />}
      {isError && <ErrorState message={(error as Error).message} />}
      {start.isError && <ErrorState message={apiErrorMessage(start.error, 'Impossible de lancer la partie.')} />}

      {session === null && !isLoading && (
        <GameStartPanel mode={mode} onStart={() => start.mutate()} isPending={start.isPending} />
      )}

      {session && (
        <>
          {/* Once the run is over the reveal below shows the real artwork, full size and in
              focus. Leaving the pixelated one above it would be showing the same picture
              twice, worse first. */}
          {!isOver && (
            <PixelBoard
              state={session}
              artworkLabel={artworkLabel}
              missing={missing}
              frameClassName={frameClassName}
            />
          )}

          {!isOver && (
            <>
              <GuessInput
                state={session}
                onGuess={(movieId) => guess.mutate(movieId)}
                isPending={guess.isPending}
                error={guess.isError ? guess.error : null}
              />
              <RevealAnswer
                mode={mode}
                onReveal={() => reveal.mutate()}
                isPending={reveal.isPending}
                error={reveal.isError ? reveal.error : null}
              />
            </>
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

/** The picture itself, framed, with the sharpness ladder underneath it. */
function PixelBoard({
  state,
  artworkLabel,
  missing,
  frameClassName,
}: {
  state: GameState
  artworkLabel: string
  missing: string
  frameClassName: string
}) {
  const artwork = state.artwork

  return (
    <section className="flex flex-col items-center gap-4 border border-ink p-5 sm:p-6">
      <div className={cn('w-full border-4 border-ink bg-surface-2', frameClassName)}>
        {artwork ? (
          <PixelArtwork pixels={artwork} label={artworkLabel} />
        ) : (
          // The answer was drawn for its artwork, so this only happens if TMDB drops the
          // file mid-run. Saying so beats an empty frame the player reads as a clue.
          <p className="flex h-full items-center justify-center px-4 py-10 text-center font-body text-sm text-subtle">
            {missing}
          </p>
        )}
      </div>

      {artwork && <SharpnessLadder step={artwork.step} steps={artwork.steps} width={artwork.width} />}
    </section>
  )
}

/**
 * How far up the ladder the picture has climbed. Drawn in full from the start, like the
 * clue game's rungs, so the length of the run is visible before the first guess is spent.
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
