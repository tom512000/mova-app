import { Link } from 'react-router-dom'
import type { PersonSummary } from '@/types/api'
import { Badge } from '@/components/ui/Badge'
import { StarRating } from '@/components/ui/StarRating'
import { ROLE_LABEL } from '@/utils/roles'

/**
 * One name in the directory, shaped like the film cards next to it in the navigation: a
 * portrait where a poster would be, the same rule, the same two lines under it. The two
 * listings are the same library read down two axes, and they should look it.
 *
 * The photograph is the one thing this card cannot count on — TMDB has none for a large
 * share of crew — so the fallback is the dotted ground a missing poster gets rather than a
 * broken image, and the name carries the card on its own when it has to.
 */
export function PersonCard({ person }: { person: PersonSummary }) {
  // The job that names them here, singular: the roles are already ordered the way a credit
  // block is, and a card is not the place to unpack somebody's whole career.
  const roles = person.roles.map((role) => ROLE_LABEL[role]).join(' · ')

  return (
    <Link to={`/people/${person.id}`} className="hard-shadow-hover group block border border-ink bg-paper">
      <div className="relative aspect-2/3 w-full overflow-hidden bg-surface-2">
        {person.profileUrl ? (
          <img
            src={person.profileUrl}
            alt={person.name}
            loading="lazy"
            className="h-full w-full object-cover grayscale transition-all duration-300 group-hover:grayscale-0 group-hover:sepia-[.5]"
          />
        ) : (
          <div className="flex h-full w-full items-center justify-center bg-[radial-gradient(currentColor_1px,transparent_1px)] bg-size-[16px_16px] text-ink/10">
            <span className="bg-paper px-2 font-mono text-[10px] uppercase tracking-widest text-subtle">
              Pas de photo
            </span>
          </div>
        )}
        {person.watchlistCount > 0 && (
          <Badge variant="solid" className="absolute right-2 top-2">
            {person.watchlistCount} à voir
          </Badge>
        )}
      </div>
      <div className="border-t border-ink p-3">
        <p className="truncate font-serif text-sm font-bold leading-tight group-hover:text-accent" title={person.name}>
          {person.name}
        </p>
        <p className="mt-1 truncate font-mono text-[10px] uppercase tracking-widest text-subtle" title={roles}>
          {roles}
        </p>
        <div className="mt-1.5 flex items-center justify-between font-mono text-[11px] text-subtle">
          {/* "Œuvres" rather than "films", the same word the person's own page uses for this
              same all-jobs tally: it counts series too, and somebody who acts in one and
              directs another cannot be counted in either unit without mislabelling half of
              it. The card cannot know which, so it names neither. */}
          <span className="tabular-nums">
            {person.watchedCount} œuvre{person.watchedCount > 1 ? 's' : ''}
          </span>
          <StarRating rating={person.averageRating} />
        </div>
      </div>
    </Link>
  )
}
