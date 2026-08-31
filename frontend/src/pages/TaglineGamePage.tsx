import { useParams } from 'react-router-dom'
import { Quote } from 'lucide-react'
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

export function TaglineGamePage() {
  const { mode } = useParams<{ mode: string }>()
  const gameMode: GameMode = mode === 'infinite' ? 'infinite' : 'daily'
  const { session, isLoading, isError, error, start, reveal, guess, isOver } = useFilmGame('tagline', gameMode)

  return (
    <div className="flex flex-col gap-8">
      <GameHeader
        game="tagline"
        mode={gameMode}
        title="L'accroche"
        rules="La phrase écrite pour vendre le film, et rien d'autre. Chaque proposition ratée ajoute un indice sous la citation."
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
          <Accroche text={session.tagline} />

          {/* The ladder only exists once a guess has been spent on it — before that the
              quotation is the whole board, and an empty panel of locked rungs under it
              would read as a promise the game has not made yet. */}
          {session.maxAttempts > 1 && <ClueLadder state={session} />}

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

          {isOver && <GameOutcome state={session} onReplay={() => start.mutate()} isReplaying={start.isPending} />}

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
 * The tagline, set as a pull quote.
 *
 * It gets the whole panel and the largest type on the page because it is the puzzle, not a
 * caption on one — the same weight the pixelated poster gets in its own game. Marketing
 * copy is written to be read big, so this is also simply how it was meant to look.
 */
function Accroche({ text }: { text: string | null }) {
  return (
    <section className="border-4 border-ink bg-ink px-6 py-12 text-paper sm:px-12 sm:py-16">
      <Quote className="mb-6 h-8 w-8 opacity-40" strokeWidth={1.5} aria-hidden />
      <p className="text-balance font-serif text-3xl font-black leading-tight tracking-tight sm:text-4xl">
        {text ?? 'Cette accroche a disparu de TMDB depuis le début de la partie.'}
      </p>
    </section>
  )
}

/**
 * The same rungs the clue game climbs, minus its first: here rung one was the quotation
 * above, so the ladder is drawn one shorter and starts at the second slot.
 */
function ClueLadder({ state }: { state: GameState }) {
  const rungs = state.maxAttempts - 1

  return (
    <section className="border border-ink p-5 sm:p-6">
      <div className="mb-4 flex flex-wrap items-baseline justify-between gap-2">
        <h2 className="font-serif text-2xl font-bold">Indices</h2>
        <span className="font-mono text-xs uppercase tracking-widest text-subtle">
          {state.clues.length} / {rungs} dévoilé{state.clues.length > 1 ? 's' : ''}
        </span>
      </div>

      <ol className="flex flex-col divide-y divide-ink/15">
        {Array.from({ length: rungs }, (_, index) => {
          const clue = state.clues[index]

          return (
            <li
              key={index}
              className="flex flex-col gap-0.5 py-3 first:pt-0 last:pb-0 sm:flex-row sm:items-baseline sm:gap-4"
            >
              <span className="w-6 shrink-0 font-mono text-[10px] text-subtle">
                {String(index + 2).padStart(2, '0')}
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
    </section>
  )
}
