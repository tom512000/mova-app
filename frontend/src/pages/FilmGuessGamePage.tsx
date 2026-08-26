import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, NavLink, useParams } from 'react-router-dom'
import { Check, Dices, X } from 'lucide-react'
import { fetchGameState, startGame, submitGuess } from '@/services/gamesService'
import { MovieSearchCombobox } from '@/components/MovieSearchCombobox'
import { Skeleton } from '@/components/Skeleton'
import { ErrorState } from '@/components/ErrorState'
import { Button } from '@/components/ui/Button'
import { Badge } from '@/components/ui/Badge'
import { apiErrorMessage } from '@/utils/apiError'
import { formatDate } from '@/utils/format'
import { cn } from '@/utils/cn'
import type { GameMode, GameState } from '@/types/api'

const MODES: { value: GameMode; label: string; blurb: string }[] = [
  { value: 'daily', label: 'Quotidien', blurb: 'Un film par jour, une seule partie.' },
  { value: 'infinite', label: 'Infini', blurb: 'Autant de parties que tu veux.' },
]

export function FilmGuessGamePage() {
  const { mode } = useParams<{ mode: string }>()
  const gameMode: GameMode = mode === 'infinite' ? 'infinite' : 'daily'
  const queryClient = useQueryClient()
  const queryKey = ['game', 'film', gameMode]

  const { data: session, isLoading, isError, error } = useQuery({
    queryKey,
    queryFn: () => fetchGameState(gameMode),
  })

  const cache = (state: GameState) => queryClient.setQueryData(queryKey, state)

  const start = useMutation({ mutationFn: () => startGame(gameMode), onSuccess: cache })
  const guess = useMutation({ mutationFn: (movieId: number) => submitGuess(gameMode, movieId), onSuccess: cache })

  const isOver = session != null && session.status !== 'in_progress'

  return (
    <div className="flex flex-col gap-8">
      <div className="border-b-4 border-ink pb-6">
        <h1 className="font-serif text-5xl font-black tracking-tighter sm:text-6xl">Le film mystère</h1>
        <p className="mt-2 max-w-xl font-body text-sm text-subtle">
          Un film que tu as vu se cache derrière les indices. Chaque proposition ratée en dévoile un de plus,
          du plus vague au plus évident.
        </p>

        <div className="mt-5 flex flex-wrap gap-1">
          {MODES.map((entry) => (
            <NavLink
              key={entry.value}
              to={`/games/film/${entry.value}`}
              className={({ isActive }) =>
                cn(
                  'border-b-2 px-4 py-2 font-sans text-xs font-semibold uppercase tracking-widest transition-colors',
                  isActive ? 'border-accent text-accent' : 'border-transparent text-subtle hover:text-ink'
                )
              }
            >
              {entry.label}
            </NavLink>
          ))}
          <p className="w-full pt-2 font-mono text-[10px] uppercase tracking-widest text-subtle">
            {MODES.find((entry) => entry.value === gameMode)?.blurb}
            {gameMode === 'daily' && session?.puzzleDate && ` · ${formatDate(session.puzzleDate)}`}
          </p>
        </div>
      </div>

      {isLoading && <Skeleton className="h-64 w-full" />}
      {isError && <ErrorState message={(error as Error).message} />}

      {start.isError && <ErrorState message={apiErrorMessage(start.error, 'Impossible de lancer la partie.')} />}

      {session === null && !isLoading && (
        <div className="flex flex-col items-center gap-4 border border-dashed border-ink/40 py-20 text-center">
          <p className="font-serif text-2xl font-bold">Prêt·e ?</p>
          <p className="max-w-md font-body text-sm text-subtle">
            {gameMode === 'daily'
              ? "Le film du jour t'attend. Une seule partie, jusqu'à minuit."
              : 'Un film au hasard parmi ceux que tu as notés.'}
          </p>
          <Button onClick={() => start.mutate()} disabled={start.isPending}>
            <Dices className="h-4 w-4" strokeWidth={2} />
            {start.isPending ? 'Tirage…' : 'Jouer'}
          </Button>
        </div>
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
            <section className="border border-ink p-5 sm:p-6">
              <div className="mb-4 flex flex-wrap items-baseline justify-between gap-2">
                <h2 className="font-serif text-2xl font-bold">Ta proposition</h2>
                <span className="font-mono text-xs uppercase tracking-widest text-subtle">
                  {session.maxAttempts - session.attemptsUsed} essai
                  {session.maxAttempts - session.attemptsUsed > 1 ? 's' : ''} restant
                  {session.maxAttempts - session.attemptsUsed > 1 ? 's' : ''}
                </span>
              </div>
              <MovieSearchCombobox
                onSelect={(movie) => guess.mutate(movie.id)}
                excludeIds={session.guesses.map((entry) => entry.movieId)}
                disabled={guess.isPending}
              />
              {guess.isError && (
                <p className="mt-3 font-mono text-xs text-accent">
                  {apiErrorMessage(guess.error, 'Proposition refusée.')}
                </p>
              )}
            </section>
          )}

          {isOver && <Outcome state={session} onReplay={() => start.mutate()} isReplaying={start.isPending} />}

          {session.guesses.length > 0 && (
            <section className="border border-ink p-5 sm:p-6">
              <h2 className="mb-4 font-serif text-2xl font-bold">Propositions ({session.guesses.length})</h2>
              <ol className="flex flex-col divide-y divide-ink/15">
                {session.guesses.map((entry, index) => (
                  <li key={entry.movieId} className="flex items-center gap-3 py-3 first:pt-0 last:pb-0">
                    <span className="w-5 shrink-0 font-mono text-[10px] text-subtle">{index + 1}</span>
                    {entry.posterUrl ? (
                      <img src={entry.posterUrl} alt="" className="h-14 w-10 shrink-0 object-cover grayscale" />
                    ) : (
                      <span className="h-14 w-10 shrink-0 bg-surface-2" aria-hidden />
                    )}
                    <span className="min-w-0 flex-1">
                      <Link to={`/movies/${entry.movieId}`} className="font-serif text-base font-bold hover:text-accent">
                        {entry.title}
                      </Link>
                      <span className="ml-2 font-mono text-xs text-subtle">{entry.releaseYear ?? '—'}</span>
                    </span>
                    {entry.correct ? (
                      <Check className="h-5 w-5 shrink-0 text-ink" strokeWidth={2.5} aria-label="Bonne réponse" />
                    ) : (
                      <X className="h-5 w-5 shrink-0 text-subtle" strokeWidth={2} aria-label="Raté" />
                    )}
                  </li>
                ))}
              </ol>
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

function Outcome({
  state,
  onReplay,
  isReplaying,
}: {
  state: GameState
  onReplay: () => void
  isReplaying: boolean
}) {
  const won = state.status === 'won'
  const answer = state.answer

  return (
    <section className={cn('border p-5 sm:p-6', won ? 'border-ink bg-ink text-paper' : 'border-ink')}>
      <div className="flex flex-col gap-5 sm:flex-row sm:items-center">
        {answer?.posterUrl && (
          <img src={answer.posterUrl} alt="" className="h-40 w-28 shrink-0 border border-current object-cover" />
        )}
        <div className="min-w-0 flex-1">
          <Badge variant={won ? 'outline' : 'accent'} className={won ? 'border-paper/50 text-paper' : undefined}>
            {won ? 'Trouvé' : 'Raté'}
          </Badge>
          <p className="mt-2 font-serif text-3xl font-black leading-tight">
            {answer ? answer.title : 'Film inconnu'}{' '}
            {answer?.releaseYear && <span className="font-normal opacity-70">({answer.releaseYear})</span>}
          </p>
          <p className="mt-1 font-mono text-xs uppercase tracking-widest opacity-70">
            {won
              ? `En ${state.attemptsUsed} essai${state.attemptsUsed > 1 ? 's' : ''}`
              : `${state.attemptsUsed} essais, aucun bon`}
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
                to="/games/film/infinite"
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
