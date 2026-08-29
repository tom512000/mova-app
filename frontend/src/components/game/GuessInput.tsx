import { MovieSearchCombobox } from '@/components/MovieSearchCombobox'
import { apiErrorMessage } from '@/utils/apiError'
import type { GameState } from '@/types/api'

/** The search box, plus how many tries are left. Identical in both games. */
export function GuessInput({
  state,
  onGuess,
  isPending,
  error,
}: {
  state: GameState
  onGuess: (movieId: string) => void
  isPending: boolean
  error: unknown
}) {
  const left = state.maxAttempts - state.attemptsUsed
  const plural = left > 1 ? 's' : ''

  return (
    <section className="border border-ink p-5 sm:p-6">
      <div className="mb-4 flex flex-wrap items-baseline justify-between gap-2">
        <h2 className="font-serif text-2xl font-bold">Ta proposition</h2>
        <span className="font-mono text-xs uppercase tracking-widest text-subtle">
          {left} essai{plural} restant{plural}
        </span>
      </div>

      <MovieSearchCombobox
        onSelect={(movie) => onGuess(movie.id)}
        excludeIds={state.guesses.map((entry) => entry.movieId)}
        disabled={isPending}
      />

      {error != null && (
        <p className="mt-3 font-mono text-xs text-accent">{apiErrorMessage(error, 'Proposition refusée.')}</p>
      )}
    </section>
  )
}
