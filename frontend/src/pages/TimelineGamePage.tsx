import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { ArrowDown, ArrowUp, Check, Dices, GripVertical, X } from 'lucide-react'
import { useFilmGame } from '@/hooks/useFilmGame'
import { GameHeader } from '@/components/game/GameHeader'
import { GameStartPanel } from '@/components/game/GameStartPanel'
import { Button } from '@/components/ui/Button'
import { Skeleton } from '@/components/Skeleton'
import { ErrorState } from '@/components/ErrorState'
import { apiErrorMessage } from '@/utils/apiError'
import { cn } from '@/utils/cn'
import type { GameMode, TimelineAttempt, TimelineBoard, TimelineCard } from '@/types/api'

export function TimelineGamePage() {
  const { mode } = useParams<{ mode: string }>()
  const gameMode: GameMode = mode === 'infinite' ? 'infinite' : 'daily'
  const { session, isLoading, isError, error, start, order, isOver } = useFilmGame('timeline', gameMode)

  const timeline = session?.timeline ?? null
  // Remounts the arranging board when a new set is dealt, which resets the arrangement
  // without an effect watching for it.
  const dealKey = timeline?.cards.map((card) => card.movieId).join(',') ?? ''

  return (
    <div className="flex flex-col gap-8">
      <GameHeader
        game="timeline"
        mode={gameMode}
        title="La chronologie"
        rules="Cinq films que tu as vus, dans le désordre. Remets-les par date de sortie, du plus ancien au plus récent. Trois essais, et on te dit seulement quelles places sont bonnes."
        puzzleDate={session?.puzzleDate}
      />

      {isLoading && <Skeleton className="h-96 w-full" />}
      {isError && <ErrorState message={(error as Error).message} />}
      {start.isError && <ErrorState message={apiErrorMessage(start.error, 'Impossible de lancer la partie.')} />}
      {order.isError && <ErrorState message={apiErrorMessage(order.error, 'Ordre refusé.')} />}

      {session === null && !isLoading && (
        <GameStartPanel
          mode={gameMode}
          onStart={() => start.mutate()}
          isPending={start.isPending}
          daily="La chronologie du jour t'attend. Une seule partie, jusqu'à minuit."
          infinite="Cinq films au hasard parmi ceux que tu as vus."
        />
      )}

      {timeline && session && (
        <>
          {!isOver && (
            <Arranger
              key={dealKey}
              cards={timeline.cards}
              attemptsLeft={session.maxAttempts - session.attemptsUsed}
              onSubmit={(ids) => order.mutate(ids)}
              isPending={order.isPending}
            />
          )}

          {isOver && <Solution board={timeline} won={session.status === 'won'} />}

          {timeline.attempts.length > 0 && <Attempts board={timeline} />}

          {isOver && gameMode === 'infinite' && (
            <div>
              <Button size="sm" onClick={() => start.mutate()} disabled={start.isPending}>
                <Dices className="h-4 w-4" strokeWidth={2} />
                {start.isPending ? 'Tirage…' : 'Nouvelle chronologie'}
              </Button>
            </div>
          )}
          {isOver && gameMode === 'daily' && (
            <Link
              to="/games/timeline/infinite"
              className="font-mono text-xs uppercase tracking-widest text-subtle hover:text-ink"
            >
              Reviens demain — ou enchaîne en mode infini &rarr;
            </Link>
          )}
        </>
      )}
    </div>
  )
}

/**
 * The board being arranged.
 *
 * Two ways to move a card, on purpose. Dragging is what the game asks for and what a mouse
 * expects; the arrows are what makes it playable at all with a keyboard, on a touchscreen,
 * or by anyone for whom a drag is fiddly. Neither is the fallback of the other — they are
 * the same move in two grammars, and both write to the same array.
 */
function Arranger({
  cards,
  attemptsLeft,
  onSubmit,
  isPending,
}: {
  cards: TimelineCard[]
  attemptsLeft: number
  onSubmit: (ids: string[]) => void
  isPending: boolean
}) {
  const [arrangement, setArrangement] = useState<string[]>(() => cards.map((card) => card.movieId))
  const [dragging, setDragging] = useState<string | null>(null)

  const byId = new Map(cards.map((card) => [card.movieId, card]))

  const move = (from: number, to: number) => {
    if (to < 0 || to >= arrangement.length) return

    const next = [...arrangement]
    const [moved] = next.splice(from, 1)
    next.splice(to, 0, moved)
    setArrangement(next)
  }

  return (
    <section className="border border-ink p-5 sm:p-6">
      <div className="mb-4 flex flex-wrap items-baseline justify-between gap-2">
        <h2 className="font-serif text-2xl font-bold">Du plus ancien au plus récent</h2>
        <span className="font-mono text-xs uppercase tracking-widest text-subtle">
          {attemptsLeft} essai{attemptsLeft > 1 ? 's' : ''} restant{attemptsLeft > 1 ? 's' : ''}
        </span>
      </div>

      <ol className="flex flex-col gap-2">
        {arrangement.map((movieId, index) => {
          const card = byId.get(movieId)
          if (!card) return null

          return (
            <li
              key={movieId}
              draggable
              onDragStart={() => setDragging(movieId)}
              onDragEnd={() => setDragging(null)}
              onDragOver={(event) => event.preventDefault()}
              onDrop={(event) => {
                event.preventDefault()
                if (dragging === null) return
                move(arrangement.indexOf(dragging), index)
                setDragging(null)
              }}
              className={cn(
                'flex items-center gap-3 border border-ink bg-surface p-2 sm:gap-4 sm:p-3',
                dragging === movieId && 'opacity-40'
              )}
            >
              <GripVertical className="h-4 w-4 shrink-0 cursor-grab text-faint" strokeWidth={2} aria-hidden />
              <span className="w-5 shrink-0 font-mono text-[10px] text-subtle">{index + 1}</span>

              {card.posterUrl ? (
                <img src={card.posterUrl} alt="" className="h-16 w-11 shrink-0 object-cover grayscale" />
              ) : (
                <span className="h-16 w-11 shrink-0 bg-surface-2" aria-hidden />
              )}

              <span className="min-w-0 flex-1 font-serif text-base font-bold leading-snug sm:text-lg">
                {card.title}
              </span>

              <span className="flex shrink-0 flex-col">
                <button
                  type="button"
                  onClick={() => move(index, index - 1)}
                  disabled={index === 0 || isPending}
                  aria-label={`Remonter ${card.title}`}
                  className="border border-ink p-1 transition-colors hover:bg-ink hover:text-paper disabled:opacity-25 disabled:hover:bg-transparent disabled:hover:text-ink"
                >
                  <ArrowUp className="h-3.5 w-3.5" strokeWidth={2.5} />
                </button>
                <button
                  type="button"
                  onClick={() => move(index, index + 1)}
                  disabled={index === arrangement.length - 1 || isPending}
                  aria-label={`Descendre ${card.title}`}
                  className="-mt-px border border-ink p-1 transition-colors hover:bg-ink hover:text-paper disabled:opacity-25 disabled:hover:bg-transparent disabled:hover:text-ink"
                >
                  <ArrowDown className="h-3.5 w-3.5" strokeWidth={2.5} />
                </button>
              </span>
            </li>
          )
        })}
      </ol>

      <div className="mt-5">
        <Button onClick={() => onSubmit(arrangement)} disabled={isPending}>
          {isPending ? 'Vérification…' : "Valider l'ordre"}
        </Button>
      </div>
    </section>
  )
}

/** The true order, with the years that were being withheld. */
function Solution({ board, won }: { board: TimelineBoard; won: boolean }) {
  const byId = new Map(board.cards.map((card) => [card.movieId, card]))

  return (
    <section className={cn('border p-5 sm:p-6', won ? 'border-ink bg-ink text-paper' : 'border-ink')}>
      <p className="font-mono text-xs uppercase tracking-widest opacity-70">{won ? 'Trouvé' : 'Raté'}</p>
      <h2 className="mt-2 font-serif text-3xl font-black leading-tight">Le bon ordre</h2>

      <ol className="mt-4 flex flex-col divide-y divide-current/20">
        {(board.solution ?? []).map((movieId, index) => {
          const card = byId.get(movieId)

          return (
            <li key={movieId} className="flex items-baseline gap-3 py-2 first:pt-0 last:pb-0">
              <span className="w-5 shrink-0 font-mono text-[10px] opacity-60">{index + 1}</span>
              <Link to={`/movies/${movieId}`} className="min-w-0 flex-1 font-serif text-lg font-bold underline-offset-4 hover:underline">
                {card?.title ?? 'Film inconnu'}
              </Link>
              <span className="shrink-0 font-mono text-sm tabular-nums opacity-70">{card?.releaseYear ?? '—'}</span>
            </li>
          )
        })}
      </ol>
    </section>
  )
}

/**
 * What each attempt was told. The point of showing them all is that the game is played on
 * their intersection — two attempts with the same slot right pin that slot down.
 */
function Attempts({ board }: { board: TimelineBoard }) {
  const byId = new Map(board.cards.map((card) => [card.movieId, card]))

  return (
    <section className="border border-ink p-5 sm:p-6">
      <h2 className="mb-4 font-serif text-2xl font-bold">Essais ({board.attempts.length})</h2>
      <ol className="flex flex-col gap-4">
        {board.attempts.map((attempt, index) => (
          <AttemptRow key={index} attempt={attempt} index={index} byId={byId} />
        ))}
      </ol>
    </section>
  )
}

function AttemptRow({
  attempt,
  index,
  byId,
}: {
  attempt: TimelineAttempt
  index: number
  byId: Map<string, TimelineCard>
}) {
  return (
    <li>
      <p className="mb-1.5 font-mono text-[10px] uppercase tracking-widest text-subtle">
        Essai {index + 1} &middot; {attempt.correctCount} / {attempt.order.length} bien placé
        {attempt.correctCount > 1 ? 's' : ''}
      </p>
      <ol className="flex flex-col divide-y divide-ink/15">
        {attempt.order.map((movieId, slot) => (
          <li key={movieId} className="flex items-center gap-3 py-1.5">
            {attempt.correct[slot] ? (
              <Check className="h-4 w-4 shrink-0 text-ink" strokeWidth={2.5} aria-label="Bien placé" />
            ) : (
              <X className="h-4 w-4 shrink-0 text-faint" strokeWidth={2} aria-label="Mal placé" />
            )}
            <span
              className={cn(
                'min-w-0 flex-1 truncate font-body text-sm',
                attempt.correct[slot] ? 'font-semibold' : 'text-subtle'
              )}
            >
              {byId.get(movieId)?.title ?? 'Film inconnu'}
            </span>
          </li>
        ))}
      </ol>
    </li>
  )
}
