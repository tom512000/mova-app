import { Link } from 'react-router-dom'
import { ArrowDown, ArrowUp, Check, X } from 'lucide-react'
import { cn } from '@/utils/cn'
import type { ComparisonFacet, FacetMatch, GameGuess } from '@/types/api'

const TILE: Record<FacetMatch, string> = {
  exact: 'border-match-exact bg-match-exact text-match-ink',
  close: 'border-match-close bg-match-close text-match-ink',
  none: 'border-ink/25 bg-surface text-ink/70',
  unknown: 'border-dashed border-ink/25 text-faint',
}

const VERDICT: Record<FacetMatch, string> = {
  exact: 'identique',
  close: 'proche',
  none: 'différent',
  unknown: 'inconnu',
}

/** The same four cases, said the way they read on a list rather than on a number. */
const LIST_VERDICT: Record<FacetMatch, string> = {
  exact: 'toutes identiques',
  close: 'partiellement en commun',
  none: 'rien en commun',
  unknown: 'inconnu',
}

/**
 * Colour alone would leave the exact/close pair unreadable for a good share of people, and
 * green against amber is exactly the pair that goes. The glyph carries the same meaning.
 */
const GLYPH: Record<FacetMatch, string> = {
  exact: '✓',
  close: '≈',
  none: '',
  unknown: '',
}

/** One guessed film, laid out attribute by attribute against the answer. */
export function ComparisonCard({ guess, attempt }: { guess: GameGuess; attempt: number }) {
  const facets = guess.facets

  return (
    <article className={cn('border p-4', guess.correct ? 'border-match-exact border-2' : 'border-ink')}>
      <header className="flex items-center gap-3">
        <span className="w-5 shrink-0 font-mono text-[10px] text-subtle">{attempt}</span>
        {guess.posterUrl ? (
          <img src={guess.posterUrl} alt="" className="h-16 w-11 shrink-0 object-cover grayscale" />
        ) : (
          <span className="h-16 w-11 shrink-0 bg-surface-2" aria-hidden />
        )}
        <span className="min-w-0 flex-1">
          <Link to={`/movies/${guess.movieId}`} className="font-serif text-lg font-bold hover:text-accent">
            {guess.title}
          </Link>
          <span className="ml-2 font-mono text-xs text-subtle">{guess.releaseYear ?? '—'}</span>
        </span>
        {guess.correct ? (
          <Check className="h-5 w-5 shrink-0 text-match-exact" strokeWidth={3} aria-label="Bonne réponse" />
        ) : (
          <X className="h-5 w-5 shrink-0 text-subtle" strokeWidth={2} aria-label="Raté" />
        )}
      </header>

      {facets && (
        /*
         * The two numbers take one slot each and the list attributes take two, which fills
         * both grids exactly: 1+1+2 then 2+2 then 2+2 on four columns, and one shared row
         * then full-width rows on two.
         */
        <div className="mt-3 grid grid-cols-2 gap-1.5 lg:grid-cols-4">
          {facets.map((facet) => (
            <FacetTile key={facet.label} facet={facet} />
          ))}
        </div>
      )}
    </article>
  )
}

function FacetTile({ facet }: { facet: ComparisonFacet }) {
  return facet.parts ? <ListTile facet={facet} /> : <NumberTile facet={facet} />
}

/** A single number: the whole tile takes the colour, and an arrow says which way to move. */
function NumberTile({ facet }: { facet: ComparisonFacet }) {
  const Arrow = facet.direction === 'up' ? ArrowUp : ArrowDown

  return (
    <div className={cn('border px-2 py-1.5', TILE[facet.match])}>
      <Label facet={facet} />
      <p className="mt-0.5 flex items-start gap-1 font-sans text-xs font-semibold leading-snug">
        <span className="min-w-0 flex-1">{facet.value}</span>
        {facet.direction && (
          <Arrow
            className="mt-px h-3 w-3 shrink-0"
            strokeWidth={3}
            aria-label={facet.direction === 'up' ? 'le film cherché est au-dessus' : 'le film cherché est en dessous'}
          />
        )}
      </p>
      <span className="sr-only">{VERDICT[facet.match]}</span>
    </div>
  )
}

/**
 * A list: the tile itself stays neutral and every value carries its own colour, so sharing
 * one genre out of three reads as one green chip instead of a single amber verdict for the
 * whole set.
 */
function ListTile({ facet }: { facet: ComparisonFacet }) {
  return (
    <div className="col-span-2 border border-ink/25 px-2 py-1.5">
      <Label facet={facet} />
      <ul className="mt-1 flex flex-wrap gap-1">
        {facet.parts?.map((part, index) => (
          <li
            // A person can hold two credits on the same film, so the value alone is not a key.
            key={`${part.value}-${index}`}
            className={cn('border px-1.5 py-0.5 font-sans text-[11px] font-semibold leading-tight', TILE[part.match])}
          >
            {part.value}
            <span className="sr-only"> — {VERDICT[part.match]}</span>
          </li>
        ))}
      </ul>
      <span className="sr-only">{LIST_VERDICT[facet.match]}</span>
    </div>
  )
}

function Label({ facet }: { facet: ComparisonFacet }) {
  return (
    // opacity rather than a colour token: the label has to sit on a green tile and on a
    // plain one, and inheriting the tile's own ink is the only thing that works on both.
    <p className="flex items-center justify-between gap-1 font-mono text-[9px] uppercase tracking-widest opacity-70">
      <span className="truncate">{facet.label}</span>
      {/* The glyph doubles the tile's colour; on a list the chips carry it instead. */}
      {!facet.parts && <span aria-hidden>{GLYPH[facet.match]}</span>}
    </p>
  )
}

/** Without this the colours are a puzzle of their own on the first play. */
export function ComparisonLegend() {
  return (
    <p className="flex flex-wrap items-center gap-x-4 gap-y-2 font-mono text-[10px] uppercase tracking-widest text-subtle">
      {(['exact', 'close', 'none'] as const).map((match) => (
        <span key={match} className="inline-flex items-center gap-1.5">
          <span className={cn('inline-block h-3 w-3 border', TILE[match])} aria-hidden />
          {VERDICT[match]}
        </span>
      ))}
      <span className="inline-flex items-center gap-1.5">
        <ArrowUp className="h-3 w-3" strokeWidth={3} aria-hidden />
        la valeur cherchée est plus haute
      </span>
      <span>listes comparées valeur par valeur</span>
    </p>
  )
}
