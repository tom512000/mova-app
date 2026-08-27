import { cn } from '@/utils/cn'

const ALPHABET = [...'ABCDEFGHIJKLMNOPQRSTUVWXYZ']

/**
 * The 26 letters, each carrying what became of it.
 *
 * A miss is struck through as well as greyed: on a board where the only thing separating a
 * hit from a miss is colour, anyone who cannot tell green from grey would be guessing the
 * same letter twice.
 */
export function LetterKeyboard({
  tried,
  wrong,
  onPick,
  disabled,
}: {
  tried: string[]
  wrong: string[]
  onPick: (letter: string) => void
  disabled: boolean
}) {
  return (
    <div className="flex flex-wrap gap-1.5">
      {ALPHABET.map((letter) => {
        const isTried = tried.includes(letter)
        const isWrong = wrong.includes(letter)

        return (
          <button
            key={letter}
            type="button"
            onClick={() => onPick(letter)}
            disabled={disabled || isTried}
            aria-label={`${letter}${isTried ? (isWrong ? ' — absente du titre' : ' — dans le titre') : ''}`}
            className={cn(
              'h-9 w-9 border font-mono text-sm font-bold uppercase transition-colors',
              'disabled:cursor-not-allowed',
              !isTried && 'border-ink hover:bg-ink hover:text-paper disabled:opacity-40',
              isTried && !isWrong && 'border-match-exact bg-match-exact text-match-ink',
              isWrong && 'border-ink/20 text-faint line-through'
            )}
          >
            {letter}
          </button>
        )
      })}
    </div>
  )
}
