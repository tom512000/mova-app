import { useEffect } from 'react'
import { useParams } from 'react-router-dom'
import { useFilmGame } from '@/hooks/useFilmGame'
import { GameHeader } from '@/components/game/GameHeader'
import { GameStartPanel } from '@/components/game/GameStartPanel'
import { GameOutcome } from '@/components/game/GameOutcome'
import { GuessInput } from '@/components/game/GuessInput'
import { RevealAnswer } from '@/components/game/RevealAnswer'
import { GuessList } from '@/components/game/GuessList'
import { Gallows } from '@/components/game/Gallows'
import { LetterKeyboard } from '@/components/game/LetterKeyboard'
import { SkeletonGameBoard } from '@/components/Skeleton'
import { ErrorState } from '@/components/ErrorState'
import { apiErrorMessage } from '@/utils/apiError'
import { cn } from '@/utils/cn'
import type { GameMode, HangmanBoard } from '@/types/api'

export function HangmanGamePage() {
  const { mode } = useParams<{ mode: string }>()
  const gameMode: GameMode = mode === 'infinite' ? 'infinite' : 'daily'
  const { session, isLoading, isError, error, start, reveal, guess, letter, isOver } = useFilmGame('hangman', gameMode)

  const board = session?.hangman ?? null
  const canPlay = board !== null && !isOver && !letter.isPending
  const play = letter.mutate

  // A hangman is played on the keyboard, so the keyboard should work. The on-screen letters
  // stay for the touch case and for showing what has already gone.
  useEffect(() => {
    if (!canPlay) return

    const onKeyDown = (event: KeyboardEvent) => {
      if (event.metaKey || event.ctrlKey || event.altKey) return
      // Not while they are typing a film title into the search box.
      if (event.target instanceof HTMLInputElement || event.target instanceof HTMLTextAreaElement) return
      if (event.key.length !== 1 || !/\p{L}/u.test(event.key)) return

      play(event.key)
    }

    window.addEventListener('keydown', onKeyDown)
    return () => window.removeEventListener('keydown', onKeyDown)
    // mutate is stable; the mutation object itself is not, and depending on it would tear
    // the listener down and put it back on every render.
  }, [canPlay, play])

  return (
    <div className="flex flex-col gap-8">
      <GameHeader
        game="hangman"
        mode={gameMode}
        title="Le film pendu"
        rules="Le titre d'un film que tu as vu, lettre par lettre. Chaque lettre absente coûte un trait — et nommer le film directement met fin à la partie, dans un sens ou dans l'autre."
        puzzleDate={session?.puzzleDate}
      />

      {isLoading && <SkeletonGameBoard height={280} />}
      {isError && <ErrorState message={(error as Error).message} />}
      {start.isError && <ErrorState message={apiErrorMessage(start.error, 'Impossible de lancer la partie.')} />}

      {session === null && !isLoading && (
        <GameStartPanel mode={gameMode} onStart={() => start.mutate()} isPending={start.isPending} />
      )}

      {session && board && (
        <>
          <section className="border border-ink p-5 sm:p-6">
            <div className="flex flex-col items-center gap-6 sm:flex-row sm:items-start sm:gap-8">
              <Gallows livesLeft={board.livesLeft} lives={board.lives} />

              <div className="flex min-w-0 flex-1 flex-col gap-4">
                <MaskedTitle board={board} />
                <p className="font-mono text-xs uppercase tracking-widest text-subtle">
                  {board.livesLeft} vie{board.livesLeft > 1 ? 's' : ''} sur {board.lives}
                </p>
              </div>
            </div>
          </section>

          {!isOver && (
            <section className="border border-ink p-5 sm:p-6">
              <div className="mb-4 flex flex-wrap items-baseline justify-between gap-2">
                <h2 className="font-serif text-2xl font-bold">Les lettres</h2>
                <span className="font-mono text-xs uppercase tracking-widest text-subtle">
                  Le clavier marche aussi
                </span>
              </div>
              <LetterKeyboard
                tried={board.tried}
                wrong={board.wrong}
                onPick={(value) => letter.mutate(value)}
                disabled={!canPlay}
              />
              {letter.isError && (
                <p className="mt-3 font-mono text-xs text-accent">
                  {apiErrorMessage(letter.error, 'Lettre refusée.')}
                </p>
              )}
            </section>
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
              <h2 className="mb-4 font-serif text-2xl font-bold">Films proposés ({session.guesses.length})</h2>
              <GuessList guesses={session.guesses} />
            </section>
          )}
        </>
      )}
    </div>
  )
}

/**
 * The title on its dashes, wrapped word by word so a long one never breaks mid-word — the
 * length of each word is half of what a player reads off the board.
 */
function MaskedTitle({ board }: { board: HangmanBoard }) {
  const words: (string | null)[][] = [[]]
  for (const char of board.chars) {
    if (char === ' ') words.push([])
    else words[words.length - 1].push(char)
  }

  return (
    <p
      className="flex flex-wrap items-end gap-x-4 gap-y-3"
      role="img"
      aria-label={`Titre à trouver : ${board.chars.map((char) => char ?? '_').join('')}`}
    >
      {words.map((word, wordIndex) => (
        <span key={wordIndex} className="flex gap-1" aria-hidden>
          {word.map((char, index) => (
            <Slot key={index} char={char} />
          ))}
        </span>
      ))}
    </p>
  )
}

function Slot({ char }: { char: string | null }) {
  // Punctuation and digits sit on the line without a dash under them, the way you write
  // them out by hand; only the slots that had to be won are underlined.
  const isLetter = char === null || /\p{L}/u.test(char)

  return (
    <span
      className={cn(
        'inline-flex h-9 min-w-5 items-end justify-center font-serif text-3xl font-black leading-none',
        isLetter && 'border-b-2 border-ink'
      )}
    >
      {char ?? ' '}
    </span>
  )
}
