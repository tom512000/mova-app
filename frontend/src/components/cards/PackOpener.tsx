import { useEffect, useRef, useState } from 'react'
import { Check, SkipForward } from 'lucide-react'
import type { PackResult } from '@/types/api'
import { Button } from '@/components/ui/Button'
import { CardBack, CardFace } from '@/components/cards/CardFace'
import { cn } from '@/utils/cn'
import { PACK_COPY, RARITY_COPY, formatJetons, rarityRank } from '@/utils/cards'

/** Milliseconds between one card landing and the next. */
const DEAL_INTERVAL = 130

/** How long after a card lands before it turns itself over, if nobody clicks it. */
const AUTO_FLIP_DELAY = 620

const prefersReducedMotion = () =>
  typeof window !== 'undefined' && window.matchMedia('(prefers-reduced-motion: reduce)').matches

/**
 * The reveal.
 *
 * The whole pack arrives in one response, so none of this is asking the server anything —
 * it is presentation over data the client already holds, which is what makes it safe to
 * skip entirely. A dropped connection costs the animation and never the cards.
 *
 * Timed by a single requestAnimationFrame loop measuring against performance.now(), not by
 * N setTimeouts. Timeout drift stacks across a dozen cards, and a backgrounded tab fires
 * them all at once on return — rAF pauses with the tab, which is the behaviour you want
 * when somebody switches away mid-pack. The loop is cancelled in the effect's cleanup, the
 * way PosterWall cancels its glide.
 *
 * Reduced motion is not a degraded path here: everything is dealt and face-up immediately,
 * which is the same information in less time. "Tout révéler" does the same on demand, for
 * the player who just wants to see what they got.
 */
export function PackOpener({
  result,
  onDone,
}: {
  result: PackResult
  onDone: () => void
}) {
  const reduced = prefersReducedMotion()
  const [dealt, setDealt] = useState(reduced ? result.cards.length : 0)
  const [flipped, setFlipped] = useState<Set<number>>(
    () => new Set(reduced ? result.cards.map((_, index) => index) : [])
  )
  const frame = useRef<number | null>(null)

  useEffect(() => {
    if (reduced) return

    const start = performance.now()
    const total = result.cards.length

    const tick = () => {
      const elapsed = performance.now() - start
      // Derived from the clock rather than incremented, so a dropped frame loses smoothness
      // and never a card.
      const landed = Math.min(total, Math.floor(elapsed / DEAL_INTERVAL) + 1)
      setDealt(landed)

      const due = Math.min(total, Math.floor((elapsed - AUTO_FLIP_DELAY) / DEAL_INTERVAL) + 1)
      if (due > 0) {
        setFlipped((previous) => {
          if (previous.size >= due) return previous
          const next = new Set(previous)
          for (let index = 0; index < due; index += 1) next.add(index)
          return next
        })
      }

      if (landed < total || elapsed < AUTO_FLIP_DELAY + total * DEAL_INTERVAL) {
        frame.current = requestAnimationFrame(tick)
      }
    }

    frame.current = requestAnimationFrame(tick)

    return () => {
      if (frame.current !== null) cancelAnimationFrame(frame.current)
    }
  }, [reduced, result])

  const revealAll = () => {
    if (frame.current !== null) cancelAnimationFrame(frame.current)
    setDealt(result.cards.length)
    setFlipped(new Set(result.cards.map((_, index) => index)))
  }

  const everythingShown = flipped.size >= result.cards.length
  const newCards = result.cards.filter((card) => card.isNew).length
  const duplicates = result.cards.length - newCards

  return (
    <section className="flex flex-col gap-6 border-2 border-ink bg-surface p-4 sm:p-6">
      <header className="flex flex-wrap items-baseline justify-between gap-3 border-b border-ink pb-3">
        <h2 className="font-serif text-2xl font-black tracking-tight">
          {PACK_COPY[result.kind].name}
        </h2>
        <p className="font-mono text-[10px] uppercase tracking-widest text-subtle">
          {result.cost > 0 ? `−${formatJetons(result.cost)}` : 'Gratuite'}
        </p>
      </header>

      {/* Every card announced as it turns, so the whole thing works with the animation off
          and for anybody not watching the screen. */}
      <p aria-live="polite" className="sr-only">
        {result.cards
          .filter((_, index) => flipped.has(index))
          .map((card) => `${card.card.label}, ${RARITY_COPY[card.card.rarity].name}`)
          .join('. ')}
      </p>

      <ol className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
        {result.cards.map((packCard, index) => (
          <li
            key={packCard.card.id}
            className={cn(
              'transition-all duration-300 ease-out',
              index < dealt ? 'translate-x-0 opacity-100' : '-translate-x-6 opacity-0'
            )}
          >
            <RevealedCard
              packCard={packCard}
              flipped={flipped.has(index)}
              onFlip={() => setFlipped((previous) => new Set(previous).add(index))}
            />
          </li>
        ))}
      </ol>

      <footer className="flex flex-wrap items-center justify-between gap-3 border-t border-ink pt-4">
        <p className="font-mono text-[11px] uppercase tracking-widest text-subtle">
          <b className="text-ink">{newCards}</b> nouvelle{newCards > 1 ? 's' : ''} ·{' '}
          <b className="text-ink">{duplicates}</b> doublon{duplicates > 1 ? 's' : ''}
          {result.jetonsEarned > 0 && (
            <>
              {' '}
              · <b className="text-ink">+{formatJetons(result.jetonsEarned)}</b>
            </>
          )}
        </p>

        <div className="flex items-center gap-2">
          {!everythingShown && (
            <Button variant="ghost" size="sm" onClick={revealAll}>
              <SkipForward className="h-4 w-4" strokeWidth={2} />
              Tout révéler
            </Button>
          )}
          <Button variant="secondary" size="sm" onClick={onDone}>
            <Check className="h-4 w-4" strokeWidth={2.5} />
            Terminé
          </Button>
        </div>
      </footer>

      {result.dailyCapReached && (
        <p className="border-t border-ink/20 pt-3 font-body text-sm italic text-subtle">
          Le plafond quotidien de jetons sur les doublons de Pochette est atteint. Les paquets
          gratuits continuent de donner des cartes — ils ne rapportent plus de jetons
          aujourd&rsquo;hui.
        </p>
      )}
    </section>
  )
}

/**
 * One card, turning over.
 *
 * The click handler sits on the wrapper and never on a face: Chromium will not hit-test
 * into preserve-3d children, which is the trap PosterWall documents. The beat that plays on
 * landing escalates with the tier and is spent once — a rule wipe at Rare, a print
 * misregistration at Super Rare, a ground sweep at Ultra Rare, and the hard stamp at
 * Légendaire, which is also the only card printed in negative.
 */
function RevealedCard({
  packCard,
  flipped,
  onFlip,
}: {
  packCard: PackResult['cards'][number]
  flipped: boolean
  onFlip: () => void
}) {
  const rarity = packCard.card.rarity
  const rank = rarityRank(rarity)

  return (
    <div className="group flex flex-col gap-1.5">
      <button
        type="button"
        onClick={onFlip}
        aria-label={flipped ? packCard.card.label : 'Retourner la carte'}
        className="relative block w-full [perspective:1000px] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
      >
        <div
          className={cn(
            'card-flip relative w-full',
            flipped && '[transform:rotateY(180deg)]',
            flipped && rank >= rarityRank('super_rare') && 'card-misregister',
            flipped && rarity === 'legendary' && 'card-stamp'
          )}
        >
          <div className="card-flip-face">
            <CardBack />
          </div>
          <div className="card-flip-face absolute inset-0 [transform:rotateY(180deg)]">
            <CardFace
              card={packCard.card}
              className={cn(flipped && rank >= rarityRank('ultra_rare') && 'card-sweep')}
            />
          </div>
        </div>

        {/* The rule that wipes across from Rare up. One shot, one pixel, no colour — and it
            has to end invisible, which is the animation's job and not a transition's: as a
            transition it settled at full width and stayed drawn across the picture. */}
        {flipped && rank >= rarityRank('rare') && (
          <span
            aria-hidden
            className="card-rule-wipe pointer-events-none absolute inset-x-0 top-1/2 h-px bg-ink"
          />
        )}
      </button>

      {flipped && (
        <p className="min-h-4 text-center font-mono text-[10px] uppercase tracking-widest">
          {packCard.isNew ? (
            <span className="text-ink">Nouvelle</span>
          ) : (
            <span className="text-subtle">
              Doublon{packCard.jetons > 0 && ` · +${formatJetons(packCard.jetons)}`}
            </span>
          )}
          {packCard.wasPity && <span className="text-subtle"> · pitié</span>}
          {packCard.wasGuaranteed && !packCard.wasPity && <span className="text-subtle"> · garantie</span>}
        </p>
      )}
    </div>
  )
}
