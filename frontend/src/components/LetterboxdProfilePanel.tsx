import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { ExternalLink } from 'lucide-react'
import { fetchLetterboxdProfile } from '@/services/profileService'
import { Skeleton } from '@/components/Skeleton'
import { formatDate } from '@/utils/format'
import type { FavouriteFilm, LetterboxdProfile } from '@/types/api'

/**
 * The Letterboxd page behind the library, read from profile.csv.
 *
 * Kept visually apart from the account details above it because it is a different kind of
 * thing: nothing here is editable, all of it comes from a file, and it is only as current as
 * the last import — which is why the panel says when that was.
 */
export function LetterboxdProfilePanel() {
  const { data, isLoading, isError } = useQuery({
    queryKey: ['letterboxd-profile'],
    queryFn: fetchLetterboxdProfile,
  })

  return (
    <section className="border border-ink p-5 sm:p-6">
      <h2 className="font-serif text-2xl font-bold">Profil Letterboxd</h2>

      {isLoading && <Skeleton className="mt-5 h-48 w-full" />}

      {isError && (
        <p className="mt-4 font-mono text-xs text-accent">Impossible de charger le profil Letterboxd.</p>
      )}

      {!isLoading && !isError && (data ? <Profile profile={data} /> : <NothingImported />)}
    </section>
  )
}

/** Shown until a profile.csv has been through the importer. */
function NothingImported() {
  return (
    <div className="mt-4 border border-dashed border-ink/40 px-4 py-10 text-center">
      <p className="font-body text-sm text-subtle">
        Aucun <span className="font-mono text-xs">profile.csv</span> importé pour l'instant.
      </p>
      <Link
        to="/import"
        className="mt-3 inline-block font-mono text-xs uppercase tracking-widest underline decoration-accent decoration-2 underline-offset-4"
      >
        Importer un export Letterboxd &rarr;
      </Link>
    </div>
  )
}

function Profile({ profile }: { profile: LetterboxdProfile }) {
  const facts = [
    profile.username && { label: 'Pseudo', value: `@${profile.username}` },
    profile.fullName && { label: 'Nom', value: profile.fullName },
    profile.joinedOn && { label: 'Inscrit·e le', value: formatDate(profile.joinedOn) },
    profile.location && { label: 'Lieu', value: profile.location },
    profile.pronoun && { label: 'Pronoms', value: profile.pronoun },
  ].filter((fact): fact is { label: string; value: string } => Boolean(fact))

  return (
    <div className="mt-5 flex flex-col gap-6">
      {/* Only the fields Letterboxd actually holds are drawn. A grid of dashes would say
          nothing and take the same room as something worth reading. */}
      {facts.length > 0 && (
        <dl className="grid grid-cols-1 gap-px border border-ink bg-ink/15 sm:grid-cols-2">
          {facts.map((fact) => (
            <div key={fact.label} className="bg-paper p-4">
              <dt className="font-mono text-[10px] uppercase tracking-widest text-subtle">{fact.label}</dt>
              <dd className="mt-1 truncate font-serif text-lg font-bold">{fact.value}</dd>
            </div>
          ))}
        </dl>
      )}

      {profile.bio && (
        <blockquote className="border-l-4 border-ink pl-4 font-body text-sm italic leading-relaxed">
          {profile.bio}
        </blockquote>
      )}

      {profile.website && (
        <a
          href={profile.website}
          target="_blank"
          rel="noopener noreferrer"
          className="inline-flex items-center gap-1.5 self-start font-mono text-xs underline decoration-accent decoration-2 underline-offset-4"
        >
          {profile.website}
          <ExternalLink className="h-3 w-3" strokeWidth={2} aria-hidden />
        </a>
      )}

      <Favourites films={profile.favourites} />

      <p className="font-mono text-[10px] uppercase tracking-widest text-faint">
        D'après le dernier import &middot; {formatDate(profile.importedAt)}
      </p>
    </div>
  )
}

/**
 * The four pinned films, in their slots.
 *
 * Four across at every width rather than wrapping: the row *is* the profile header on
 * Letterboxd, and breaking it into two lines on a phone would lose the thing being shown.
 */
function Favourites({ films }: { films: FavouriteFilm[] }) {
  return (
    <div>
      <h3 className="mb-3 font-mono text-[10px] uppercase tracking-widest text-subtle">Films favoris</h3>

      {films.length === 0 ? (
        <p className="font-body text-sm text-subtle">Aucun film épinglé sur ce profil.</p>
      ) : (
        <ol className="grid grid-cols-4 gap-2 sm:gap-3">
          {films.map((film) => (
            <li key={film.movieId}>
              <Link to={`/movies/${film.movieId}`} className="group block">
                {film.posterUrl ? (
                  <img
                    src={film.posterUrl}
                    alt=""
                    className="aspect-2/3 w-full border border-ink object-cover grayscale transition-[filter] group-hover:grayscale-0"
                  />
                ) : (
                  <span className="block aspect-2/3 w-full border border-ink bg-surface-2" aria-hidden />
                )}
                <span className="mt-1.5 block font-serif text-[11px] font-bold leading-tight group-hover:text-accent sm:text-sm">
                  {film.title}
                </span>
                <span className="block font-mono text-[10px] tabular-nums text-subtle">
                  {film.releaseYear ?? '—'}
                </span>
              </Link>
            </li>
          ))}
        </ol>
      )}
    </div>
  )
}
