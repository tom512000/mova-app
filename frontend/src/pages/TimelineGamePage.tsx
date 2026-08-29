import { useRef, useState } from 'react'
import type { KeyboardEvent as ReactKeyboardEvent, PointerEvent as ReactPointerEvent } from 'react'
import { Link, useParams } from 'react-router-dom'
import { Check, Dices, X } from 'lucide-react'
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
 * The board being arranged: five posters in a strip, dragged into order left to right.
 *
 * The array is not touched while a drag is in flight. Reordering it live would move the
 * card out from under the pointer and make it jump, so instead every card is offset with a
 * transform — the dragged one follows the pointer, the ones it displaces slide one slot —
 * and the splice happens once, on release. That is also what lets the others animate: they
 * keep their DOM position throughout, so a CSS transition has something to interpolate.
 *
 * Pointer events rather than HTML5 drag-and-drop, which does not fire on touchscreens at
 * all. Arrow keys move the focused card for the same reason in reverse: a drag is not a
 * gesture everyone can make, and this costs nothing on screen.
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
  const [drag, setDrag] = useState<Drag | null>(null)
  const [settling, setSettling] = useState(false)

  const slots = useRef<(HTMLDivElement | null)[]>([])
  const grabbedAt = useRef(0)
  const centers = useRef<number[]>([])

  const byId = new Map(cards.map((card) => [card.movieId, card]))

  const reorder = (from: number, to: number) => {
    const next = [...arrangement]
    const [moved] = next.splice(from, 1)
    next.splice(to, 0, moved)
    setArrangement(next)
  }

  function beginDrag(event: ReactPointerEvent<HTMLDivElement>, index: number) {
    if (isPending) return

    const rendered = slots.current.slice(0, arrangement.length)
    if (rendered.some((slot) => slot === null)) return

    // Measured from the DOM rather than computed from a width and a gap: the strip is
    // fluid, and this way the arithmetic below cannot drift from what is on screen.
    const boxes = (rendered as HTMLDivElement[]).map((slot) => slot.getBoundingClientRect())
    centers.current = boxes.map((box) => box.left + box.width / 2)
    grabbedAt.current = event.clientX

    event.currentTarget.setPointerCapture(event.pointerId)
    setDrag({ from: index, to: index, dx: 0, step: boxes.length > 1 ? boxes[1].left - boxes[0].left : 0 })
  }

  function continueDrag(event: ReactPointerEvent<HTMLDivElement>) {
    if (!drag) return

    const dx = event.clientX - grabbedAt.current
    const carried = centers.current[drag.from] + dx

    // The slot the card is nearest to, which is the one the eye has already picked.
    let to = 0
    centers.current.forEach((center, index) => {
      if (Math.abs(center - carried) < Math.abs(centers.current[to] - carried)) to = index
    })

    setDrag({ ...drag, to, dx })
  }

  function endDrag() {
    if (!drag) return

    if (drag.to !== drag.from) {
      reorder(drag.from, drag.to)

      // On the commit frame a displaced card changes slot *and* loses its offset, and the
      // two cancel out: it is already where it belongs. Left transitioning, it would
      // animate to that spot from a slot away — the jump this suppresses. Two frames,
      // because one only queues the style, it does not paint it.
      setSettling(true)
      requestAnimationFrame(() => requestAnimationFrame(() => setSettling(false)))
    }

    setDrag(null)
  }

  function moveWithKeyboard(event: ReactKeyboardEvent<HTMLDivElement>, index: number) {
    const step = event.key === 'ArrowLeft' ? -1 : event.key === 'ArrowRight' ? 1 : 0
    const to = index + step
    if (step === 0 || isPending || to < 0 || to >= arrangement.length) return

    event.preventDefault()
    reorder(index, to)
    // Focus follows the card, not the slot — otherwise a second press moves its new
    // neighbour instead of continuing to move the card being carried.
    requestAnimationFrame(() => slots.current[to]?.focus())
  }

  return (
    <section className="border border-ink p-5 sm:p-6">
      <div className="mb-4 flex flex-wrap items-baseline justify-between gap-2">
        <h2 className="font-serif text-2xl font-bold">Range-les par date de sortie</h2>
        <span className="font-mono text-xs uppercase tracking-widest text-subtle">
          {attemptsLeft} essai{attemptsLeft > 1 ? 's' : ''} restant{attemptsLeft > 1 ? 's' : ''}
        </span>
      </div>

      <div className="border border-dashed border-ink/40 p-3 sm:p-4">
        <div className="mb-3 flex items-baseline justify-between font-mono text-[10px] uppercase tracking-widest text-subtle">
          <span>&larr; Le plus ancien</span>
          <span>Le plus récent &rarr;</span>
        </div>

        <div className="flex gap-2 sm:gap-3">
          {arrangement.map((movieId, index) => {
            const card = byId.get(movieId)
            if (!card) return null

            const held = drag?.from === index

            return (
              <div
                key={movieId}
                ref={(node) => {
                  slots.current[index] = node
                }}
                // A control that carries something rather than one that fires: "button" is
                // the closest role there is, and the label says what the keys do.
                role="button"
                tabIndex={isPending ? -1 : 0}
                aria-label={`${card.title}, position ${index + 1} sur ${arrangement.length}. Flèches gauche et droite pour le déplacer.`}
                onPointerDown={(event) => beginDrag(event, index)}
                onPointerMove={continueDrag}
                onPointerUp={endDrag}
                onPointerCancel={endDrag}
                onKeyDown={(event) => moveWithKeyboard(event, index)}
                style={{ transform: `translateX(${offsetOf(index, drag)}px)` }}
                className={cn(
                  'min-w-0 flex-1 cursor-grab touch-none select-none border border-ink bg-surface',
                  'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent',
                  held
                    ? // No transition on the one under the pointer: it has to track the
                    // finger exactly, and easing towards it reads as lag.
                    'relative z-10 cursor-grabbing shadow-[4px_4px_0_0_var(--ink)]'
                    : !settling && 'transition-transform duration-150 ease-out'
                )}
              >
                {card.posterUrl ? (
                  <img
                    src={card.posterUrl}
                    alt=""
                    draggable={false}
                    className="aspect-2/3 w-full object-cover grayscale"
                  />
                ) : (
                  <span className="block aspect-2/3 w-full bg-surface-2" aria-hidden />
                )}
                <span className="block px-1.5 py-2 text-center font-serif text-xs font-bold leading-tight sm:px-2 sm:text-sm">
                  {card.title}
                </span>
              </div>
            )
          })}
        </div>
      </div>

      <div className="mt-5 flex flex-wrap items-center gap-4">
        <Button onClick={() => onSubmit(arrangement)} disabled={isPending}>
          {isPending ? 'Vérification…' : "Valider l'ordre"}
        </Button>
        <p className="font-mono text-[10px] uppercase tracking-widest text-faint">
          Glisse une affiche &mdash;
        </p>
      </div>
    </section>
  )
}

/** A drag in flight: where it started, the slot it is over, and how far it has travelled. */
interface Drag {
  from: number
  to: number
  dx: number
  /** Distance between two slots, so a displaced card knows how far to slide. */
  step: number
}

/**
 * How far a card sits from its own slot right now.
 *
 * The carried one follows the pointer. Everything between where it came from and where it
 * is headed shifts one slot the other way, which is what opens the gap it will drop into.
 */
function offsetOf(index: number, drag: Drag | null): number {
  if (!drag) return 0
  if (index === drag.from) return drag.dx
  if (drag.from < drag.to && index > drag.from && index <= drag.to) return -drag.step
  if (drag.to < drag.from && index >= drag.to && index < drag.from) return drag.step

  return 0
}

/**
 * The true order, with the years that were being withheld.
 *
 * Drawn as the same strip the player was just arranging, so the answer lands as a
 * correction of the thing they built rather than as a list to re-read from the top.
 */
function Solution({ board, won }: { board: TimelineBoard; won: boolean }) {
  const byId = new Map(board.cards.map((card) => [card.movieId, card]))

  return (
    <section className={cn('border p-5 sm:p-6', won ? 'border-ink bg-ink text-paper' : 'border-ink')}>
      <p className="font-mono text-xs uppercase tracking-widest opacity-70">{won ? 'Trouvé' : 'Raté'}</p>
      <h2 className="mt-2 font-serif text-3xl font-black leading-tight">Le bon ordre</h2>

      <ol className="mt-4 flex gap-2 sm:gap-3">
        {(board.solution ?? []).map((movieId) => {
          const card = byId.get(movieId)

          return (
            <li key={movieId} className="min-w-0 flex-1">
              <Link to={`/movies/${movieId}`} className="block border border-current">
                {card?.posterUrl ? (
                  <img src={card.posterUrl} alt="" className="aspect-2/3 w-full object-cover grayscale" />
                ) : (
                  <span className="block aspect-2/3 w-full bg-current/10" aria-hidden />
                )}
                <span className="block px-1 py-1.5 text-center font-mono text-xs font-semibold tabular-nums">
                  {card?.releaseYear ?? '—'}
                </span>
              </Link>
              <span className="mt-1 block text-center font-serif text-[11px] font-bold leading-tight opacity-70">
                {card?.title ?? 'Film inconnu'}
              </span>
            </li>
          )
        })}
      </ol>
    </section>
  )
}

/**
 * What each attempt was told. The point of showing them all is that the game is played on
 * their intersection — two attempts with the same slot right pin that slot down, which is
 * far easier to see with the strips stacked than it would be down a column of titles.
 */
function Attempts({ board }: { board: TimelineBoard }) {
  const byId = new Map(board.cards.map((card) => [card.movieId, card]))

  return (
    <section className="border border-ink p-5 sm:p-6">
      <h2 className="mb-4 font-serif text-2xl font-bold">Essais ({board.attempts.length})</h2>
      <ol className="flex flex-col gap-5">
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
      <p className="mb-2 font-mono text-[10px] uppercase tracking-widest text-subtle">
        Essai {index + 1} &middot; {attempt.correctCount} / {attempt.order.length} bien placé
        {attempt.correctCount > 1 ? 's' : ''}
      </p>

      <ol className="flex gap-2 sm:gap-3">
        {attempt.order.map((movieId, slot) => {
          const card = byId.get(movieId)
          const right = attempt.correct[slot]

          return (
            <li key={movieId} className="min-w-0 flex-1">
              <div
                className={cn(
                  'border',
                  // A slot that held is drawn in full ink; the rest fade back, so the shape
                  // of what is already pinned down reads at a glance across the attempts.
                  right ? 'border-ink' : 'border-ink/25'
                )}
              >
                {card?.posterUrl ? (
                  <img
                    src={card.posterUrl}
                    alt=""
                    className={cn('aspect-2/3 w-full object-cover grayscale', !right && 'opacity-40')}
                  />
                ) : (
                  <span className="block aspect-2/3 w-full bg-surface-2" aria-hidden />
                )}
                <span className="flex items-center justify-center py-1">
                  {right ? (
                    <Check className="h-3.5 w-3.5 text-ink" strokeWidth={2.5} aria-label="Bien placé" />
                  ) : (
                    <X className="h-3.5 w-3.5 text-faint" strokeWidth={2} aria-label="Mal placé" />
                  )}
                </span>
              </div>
              <span
                className={cn(
                  'mt-1 block text-center font-serif text-[11px] leading-tight',
                  right ? 'font-bold' : 'font-normal text-subtle'
                )}
              >
                {card?.title ?? 'Film inconnu'}
              </span>
            </li>
          )
        })}
      </ol>
    </li>
  )
}
