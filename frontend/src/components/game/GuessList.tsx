import { Link } from 'react-router-dom'
import { Check, X } from 'lucide-react'
import { cn } from '@/utils/cn'
import type { GameGuess } from '@/types/api'

/**
 * The films named so far, in play order. Shared by the clue and poster games, which both
 * answer a guess with nothing but right or wrong — the comparison game has its own card.
 */
export function GuessList({ guesses, tone = 'ink' }: { guesses: GameGuess[]; tone?: 'ink' | 'colour' }) {
  return (
    <ol className="flex flex-col divide-y divide-ink/15">
      {guesses.map((entry, index) => (
        <li key={entry.movieId} className="flex items-center gap-3 py-3 first:pt-0 last:pb-0">
          <span className="w-5 shrink-0 font-mono text-[10px] text-subtle">{index + 1}</span>
          {entry.posterUrl ? (
            <img
              src={entry.posterUrl}
              alt=""
              // In the poster game the artwork is the subject, so the guesses keep their
              // colour: holding one palette against another is the whole move.
              className={cn('h-14 w-10 shrink-0 object-cover', tone === 'ink' && 'grayscale')}
            />
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
  )
}
