import { Link, useParams } from 'react-router-dom'
import { Dices, Check, X } from 'lucide-react'
import { useFilmGame } from '@/hooks/useFilmGame'
import { GameHeader } from '@/components/game/GameHeader'
import { GameStartPanel } from '@/components/game/GameStartPanel'
import { RevealAnswer } from '@/components/game/RevealAnswer'
import { Button } from '@/components/ui/Button'
import { SkeletonDuelTable } from '@/components/Skeleton'
import { ErrorState } from '@/components/ErrorState'
import { apiErrorMessage } from '@/utils/apiError'
import { cn } from '@/utils/cn'
import type { DuelBoard, DuelCard, DuelRound, GameMode, GameStatus } from '@/types/api'

export function DuelGamePage() {
  const { mode } = useParams<{ mode: string }>()
  const gameMode: GameMode = mode === 'infinite' ? 'infinite' : 'daily'
  const { session, isLoading, isError, error, start, reveal, pick, isOver } = useFilmGame('duel', gameMode)

  const duel = session?.duel ?? null

  return (
    <div className="flex flex-col gap-8">
      <GameHeader
        game="duel"
        mode={gameMode}
        title="Le duel"
        rules="Deux films que tu as vus, côte à côte. Lequel as-tu noté le plus haut ? Une seule erreur arrête la série."
        puzzleDate={session?.puzzleDate}
      />

      {isLoading && <SkeletonDuelTable />}
      {isError && <ErrorState message={(error as Error).message} />}
      {start.isError && <ErrorState message={apiErrorMessage(start.error, 'Impossible de lancer la partie.')} />}
      {pick.isError && <ErrorState message={apiErrorMessage(pick.error, 'Choix refusé.')} />}

      {session === null && !isLoading && (
        <GameStartPanel
          mode={gameMode}
          onStart={() => start.mutate()}
          isPending={start.isPending}
          daily="Le duel du jour t'attend. Une seule série, jusqu'à minuit."
          infinite="Des paires tirées au hasard, jusqu'à ta première erreur."
        />
      )}

      {duel && session && (
        <>
          <Scoreboard board={duel} isOver={isOver} />

          {/* Once the run is over the pair below has already been read out by the verdict,
              with the two ratings on it. Leaving the table up would offer a click that
              cannot land. */}
          {duel.cards && !isOver && (
            <>
              <Table cards={duel.cards} onPick={(movieId) => pick.mutate(movieId)} isPending={pick.isPending} />
              <RevealAnswer
                mode={gameMode}
                onReveal={() => reveal.mutate()}
                isPending={reveal.isPending}
                error={reveal.isError ? reveal.error : null}
              />
            </>
          )}

          {isOver && (
            <Verdict
              board={duel}
              status={session.status}
              mode={gameMode}
              onReplay={() => start.mutate()}
              isReplaying={start.isPending}
            />
          )}

          {duel.history.length > 0 && (
            <section className="border border-ink p-5 sm:p-6">
              <h2 className="mb-4 font-serif text-2xl font-bold">Tes verdicts ({duel.history.length})</h2>
              <ol className="flex flex-col divide-y divide-ink/15">
                {duel.history
                  .slice()
                  .reverse()
                  .map((round, index) => (
                    <HistoryRow key={`${round.pickedId}-${index}`} round={round} />
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
 * The streak, and the best one this profile has ever run up.
 *
 * A streak game needs a number to beat on screen at all times — without it every round is
 * the same round. The record is shown even while it is being set, which is the moment it is
 * worth looking at.
 */
function Scoreboard({ board, isOver }: { board: DuelBoard; isOver: boolean }) {
  return (
    <section className="flex items-stretch divide-x divide-ink border border-ink">
      <div className="flex-1 px-5 py-4">
        <p className="font-mono text-[10px] uppercase tracking-widest text-subtle">Série en cours</p>
        <p className="font-mono text-4xl font-semibold leading-none tabular-nums">{board.streak}</p>
      </div>
      <div className="flex-1 px-5 py-4">
        <p className="font-mono text-[10px] uppercase tracking-widest text-subtle">Record</p>
        <p
          className={cn(
            'font-mono text-4xl font-semibold leading-none tabular-nums',
            // Nothing else on the page is red, so this reads as "you are on it" at a glance.
            !isOver && board.streak >= board.best && board.streak > 0 && 'text-accent'
          )}
        >
          {Math.max(board.best, board.streak)}
        </p>
      </div>
    </section>
  )
}

/** The two films on the table. Clicking one is the whole move. */
function Table({
  cards,
  onPick,
  isPending,
}: {
  cards: DuelCard[]
  onPick: (movieId: string) => void
  isPending: boolean
}) {
  return (
    <section>
      <div className="grid grid-cols-2 gap-3 sm:gap-6">
        {cards.map((card) => (
          <button
            key={card.movieId}
            type="button"
            onClick={() => onPick(card.movieId)}
            disabled={isPending}
            className="group flex flex-col border border-ink text-left transition-colors hover:bg-ink hover:text-paper focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent disabled:opacity-50"
          >
            {card.posterUrl ? (
              <img
                src={card.posterUrl}
                alt=""
                // Greyscale until hovered: the app's house treatment, and here it also stops
                // a bright poster from feeling like the "right" answer.
                className="aspect-2/3 w-full object-cover grayscale transition-[filter] group-hover:grayscale-0"
              />
            ) : (
              <span className="aspect-2/3 w-full bg-surface-2" aria-hidden />
            )}
            <span className="flex flex-1 flex-col gap-1 p-3 sm:p-4">
              <span className="font-serif text-lg font-bold leading-tight sm:text-xl">{card.title}</span>
              <span className="font-mono text-xs text-subtle group-hover:text-paper/70">
                {card.releaseYear ?? '—'}
              </span>
            </span>
          </button>
        ))}
      </div>
      <p className="mt-3 text-center font-mono text-[10px] uppercase tracking-widest text-subtle">
        Lequel as-tu noté le plus haut ?
      </p>
    </section>
  )
}

/**
 * How the run ended. A duel has no film to reveal — only a number and a reason, and the
 * three endings are genuinely different: getting one wrong, running the library dry, or
 * asking which of the two on the table you had rated higher.
 */
function Verdict({
  board,
  status,
  mode,
  onReplay,
  isReplaying,
}: {
  board: DuelBoard
  status: GameStatus
  mode: GameMode
  onReplay: () => void
  isReplaying: boolean
}) {
  const givenUp = status === 'revealed'
  const last = board.history.at(-1)
  // The only round that can end a run and still be right is the one that emptied the table
  // — but a run that was given up ends on a round that was never played at all, so the
  // previous one being right says nothing about how this one finished.
  const exhausted = !givenUp && last?.correct === true
  // `best` already counts this run, so equality means the record is this one.
  const record = board.streak > 0 && board.streak >= board.best
  const backed = last?.cards.find((card) => card.movieId === last.pickedId)
  const rejected = last?.cards.find((card) => card.movieId !== last.pickedId)

  // Given up: the pair is still on the table and now carries the two ratings, which is the
  // answer to the only question this game asks.
  const [left, right] = board.cards ?? []
  const higher = left && right && (right.rating ?? 0) > (left.rating ?? 0) ? right : left
  const lower = higher === left ? right : left

  return (
    <section className={cn('border p-5 sm:p-6', exhausted ? 'border-ink bg-ink text-paper' : 'border-ink')}>
      <p className="font-mono text-xs uppercase tracking-widest opacity-70">
        {givenUp ? 'Réponse donnée' : exhausted ? 'Bibliothèque épuisée' : 'Série interrompue'}
      </p>
      <p className="mt-2 font-serif text-3xl font-black leading-tight">
        {board.streak} bon{board.streak > 1 ? 's' : ''} choix d'affilée
        {record && ' — ton record'}
      </p>
      <p className="mt-1 font-body text-sm opacity-80">
        {givenUp ? (
          higher && lower ? (
            <>
              Tu avais mis {higher.rating?.toFixed(1) ?? '—'} à «&nbsp;{higher.title}&nbsp;», contre{' '}
              {lower.rating?.toFixed(1) ?? '—'} pour «&nbsp;{lower.title}&nbsp;».
            </>
          ) : (
            'La paire a quitté la bibliothèque avant que la réponse puisse être lue.'
          )
        ) : exhausted ? (
          "Plus aucune paire à te proposer : tu as vu juste jusqu'au bout."
        ) : backed && rejected ? (
          // Naming both films rather than saying "ce film" — the pair has already left the
          // table by the time this is read, so a pronoun would point at nothing.
          <>
            Tu as choisi «&nbsp;{backed.title}&nbsp;», noté {backed.rating?.toFixed(1) ?? '—'}. «&nbsp;
            {rejected.title}&nbsp;» était à {rejected.rating?.toFixed(1) ?? '—'}.
          </>
        ) : null}
      </p>

      <div className="mt-4">
        {mode === 'infinite' ? (
          <Button
            variant={exhausted ? 'secondary' : 'primary'}
            size="sm"
            onClick={onReplay}
            disabled={isReplaying}
            className={exhausted ? 'border-paper text-paper hover:bg-paper hover:text-ink' : undefined}
          >
            <Dices className="h-4 w-4" strokeWidth={2} />
            {isReplaying ? 'Tirage…' : 'Nouvelle série'}
          </Button>
        ) : (
          <Link
            to="/games/duel/infinite"
            className="font-mono text-xs uppercase tracking-widest opacity-70 hover:opacity-100"
          >
            Reviens demain — ou enchaîne en mode infini &rarr;
          </Link>
        )}
      </div>
    </section>
  )
}

/** One settled round: both films, both notes, and which one you backed. */
function HistoryRow({ round }: { round: DuelRound }) {
  return (
    <li className="flex items-center gap-3 py-3 first:pt-0 last:pb-0">
      {round.correct ? (
        <Check className="h-5 w-5 shrink-0 text-ink" strokeWidth={2.5} aria-label="Bon choix" />
      ) : (
        <X className="h-5 w-5 shrink-0 text-accent" strokeWidth={2.5} aria-label="Mauvais choix" />
      )}
      <div className="flex min-w-0 flex-1 flex-col gap-1 sm:flex-row sm:items-baseline sm:gap-4">
        {round.cards.map((card) => (
          <span key={card.movieId} className="flex min-w-0 flex-1 items-baseline gap-2">
            <Link
              to={`/movies/${card.movieId}`}
              className={cn(
                'truncate font-serif text-base hover:text-accent',
                // The one backed is set in bold, so the row reads as a sentence: this over
                // that, and here is what your own numbers said about it.
                card.movieId === round.pickedId ? 'font-bold' : 'font-normal text-subtle'
              )}
            >
              {card.title}
            </Link>
            <span className="shrink-0 font-mono text-xs tabular-nums text-subtle">
              {card.rating?.toFixed(1) ?? '—'}
            </span>
          </span>
        ))}
      </div>
    </li>
  )
}
