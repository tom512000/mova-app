import { useQuery } from '@tanstack/react-query'
import { Link, useParams } from 'react-router-dom'
import { ArrowLeft } from 'lucide-react'
import { fetchPerson, fetchPersonFilmography } from '@/services/peopleService'
import { SkeletonMovieDetail } from '@/components/Skeleton'
import { ErrorState } from '@/components/ErrorState'
import { EmptyState } from '@/components/EmptyState'
import { PageMeta } from '@/components/PageMeta'
import { StatCard } from '@/components/StatCard'
import { Badge } from '@/components/ui/Badge'
import { StarRating } from '@/components/ui/StarRating'
import { formatRating } from '@/utils/format'
import { ROLE_LABEL, workUnit } from '@/utils/roles'
import type { FilmographyRole, PersonFilmography, PersonProfile, PersonRole, PersonWork } from '@/types/api'

/**
 * A person's page.
 *
 * It exists because clicking a name used to filter the library and nothing more, which was
 * useful and was never a page: no photograph, no average, no sense of how much of somebody's
 * work had been seen, and — worst of it — a person who directs and acts was two unrelated
 * links to two unrelated lists. Everything about one person now lands in one place.
 *
 * Drawn in two passes. The profile comes from the library and returns in milliseconds; the
 * filmography needs TMDB and fills in behind it, so the page never waits on the network to
 * show what it already knows.
 */
export function PersonPage() {
  const { id } = useParams<{ id: string }>()
  const personId = id ?? ''

  const { data: person, isLoading, isError, error } = useQuery({
    queryKey: ['person', personId],
    queryFn: () => fetchPerson(personId),
  })

  const { data: filmography } = useQuery({
    queryKey: ['person', personId, 'filmography'],
    queryFn: () => fetchPersonFilmography(personId),
    // Nothing to look up until the person is known to exist, and no point asking TMDB
    // about somebody it has never heard of.
    enabled: Boolean(person?.tmdbId),
    staleTime: Infinity,
  })

  if (isLoading) return <SkeletonMovieDetail />
  if (isError || !person) return <ErrorState message={(error as Error)?.message} />

  const watchlist = person.works.filter((work) => work.inWatchlist && !work.watched)

  return (
    <div className="flex flex-col gap-8">
      <PageMeta title={person.name} />
      <Link
        to="/movies"
        className="inline-flex w-fit items-center gap-2 font-mono text-xs uppercase tracking-widest text-subtle hover:text-accent"
      >
        <ArrowLeft className="h-4 w-4" /> Films et séries
      </Link>

      <PersonHeader person={person} />

      <Figures person={person} />

      <Jobs person={person} filmography={filmography ?? null} />

      {watchlist.length > 0 && <WatchlistStrip works={watchlist} />}

      {filmography && <StillToSee filmography={filmography} />}

      <Works person={person} />
    </div>
  )
}

function PersonHeader({ person }: { person: PersonProfile }) {
  return (
    <div className="flex flex-col gap-6 border-b-4 border-ink pb-6 sm:flex-row sm:items-end">
      <div className="w-32 shrink-0 border border-ink bg-surface-2 sm:w-40">
        <div className="aspect-2/3 w-full overflow-hidden">
          {person.profileUrl ? (
            <img
              src={person.profileUrl}
              alt={person.name}
              className="h-full w-full object-cover grayscale"
            />
          ) : (
            // Same dotted ground the poster grid uses for a missing artwork, so an
            // absent photograph reads as a known gap rather than as a broken image.
            <div className="flex h-full w-full items-center justify-center bg-[radial-gradient(currentColor_1px,transparent_1px)] bg-size-[16px_16px] text-ink/10">
              <span className="bg-paper px-2 font-mono text-[10px] uppercase tracking-widest text-subtle">
                Pas de photo
              </span>
            </div>
          )}
        </div>
      </div>

      <div className="min-w-0 flex-1">
        <h1 className="text-balance font-serif text-5xl font-black tracking-tighter sm:text-6xl">{person.name}</h1>

        <div className="mt-4 flex flex-wrap gap-2">
          {person.roles.map((role) => (
            <Badge key={role.role} variant={role === person.roles[0] ? 'solid' : 'outline'}>
              {ROLE_LABEL[role.role]}
            </Badge>
          ))}
        </div>

        {person.tmdbId !== null && (
          <a
            href={`https://www.themoviedb.org/person/${person.tmdbId}`}
            target="_blank"
            rel="noreferrer"
            className="mt-4 inline-block font-mono text-xs uppercase tracking-widest text-accent hover:underline"
          >
            TMDB &#8599;
          </a>
        )}
      </div>
    </div>
  )
}

function Figures({ person }: { person: PersonProfile }) {
  const gap = person.ratingGap

  return (
    <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
      <StatCard
        label="Œuvres vues"
        value={person.watchedCount}
        hint={`sur ${person.works.length} dans ta bibliothèque`}
      />
      <StatCard label="Ta note moyenne" value={formatRating(person.averageRating)} hint="sur ses œuvres vues" />
      <StatCard
        label="Face à ta moyenne"
        // An en dash for the minus sign, and an explicit plus: "0.4" alone would not say
        // which side of the library this person sits on.
        value={gap === null ? '—' : `${gap > 0 ? '+' : '−'}${formatRating(Math.abs(gap))}`}
        hint={gap === null ? 'rien de noté' : gap >= 0 ? 'tu le·la notes au-dessus' : 'tu le·la notes en dessous'}
      />
      <StatCard
        label="Dans ta watchlist"
        value={person.watchlistCount}
        hint={person.watchlistCount > 0 ? 'à voir de lui·elle' : 'rien en attente'}
      />
    </div>
  )
}

/**
 * One row per job, because a single blended average would hide the more interesting half of
 * anybody who does two things.
 *
 * One count per row and not two, which is a correction. Showing "vues dans ta bibliothèque"
 * beside "sur sa filmographie" put two numbers measuring different things side by side, and
 * they disagree often enough to read as a bug: Philippe Lacheau came out at 16 against 17,
 * the seventeenth being a film he is in that the library holds and has watched but carries
 * no credit row for, since only the first fifteen billed actors are imported. Both figures
 * were right. Together they looked wrong.
 *
 * So the filmography wins the column when TMDB has answered, and the library fills it when
 * TMDB has not — which is also the only thing that can be said about a series creator, TMDB
 * having no filmography notion on /tv.
 */
function Jobs({ person, filmography }: { person: PersonProfile; filmography: PersonFilmography | null }) {
  const completeness = new Map(filmography?.roles.map((role) => [role.role, role]) ?? [])

  return (
    <section>
      <h2 className="font-serif text-2xl font-black tracking-tight">Ses métiers</h2>
      <p className="mt-1 font-mono text-[11px] uppercase tracking-widest text-subtle">
        Ce que tu as vu de lui·elle, métier par métier
      </p>

      <div className="mt-4 overflow-x-auto border border-ink">
        <table className="w-full min-w-125 border-collapse text-sm">
          <thead>
            <tr className="border-b border-ink bg-surface font-mono text-[10px] uppercase tracking-widest text-subtle">
              <th className="px-4 py-2 text-left font-normal">Métier</th>
              <th className="px-4 py-2 text-left font-normal">Ce que tu en as vu</th>
              <th className="px-4 py-2 text-right font-normal">Ta note</th>
            </tr>
          </thead>
          <tbody>
            {person.roles.map((role) => (
              <tr key={role.role} className="border-b border-ink/15 last:border-b-0">
                <td className="px-4 py-3 align-top font-serif font-bold whitespace-nowrap">{ROLE_LABEL[role.role]}</td>
                <td className="px-4 py-3">
                  <Completeness role={role} filmography={completeness.get(role.role)} />
                </td>
                <td className="px-4 py-3 text-right align-top whitespace-nowrap">
                  <StarRating rating={role.averageRating} className="align-middle" />
                  <span className="ml-2 font-mono tabular-nums text-subtle">{formatRating(role.averageRating)}</span>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {filmography && (
        // The rule behind the counts, which is not obvious and would otherwise make them
        // look wrong to anybody who checked them against TMDB.
        <p className="mt-2 font-mono text-[10px] uppercase tracking-widest text-subtle">{filmography.note}</p>
      )}
    </section>
  )
}

/**
 * "9 sur 14", with the bar that makes the ratio readable without doing the division.
 *
 * Falls back to a plain count rather than to an empty cell when TMDB has said nothing:
 * knowing seven films of theirs were watched is worth more than a dash, it just cannot be
 * put against a total.
 */
function Completeness({ role, filmography }: { role: PersonRole; filmography?: FilmographyRole }) {
  if (!filmography) {
    const total = role.watchedCount + role.unwatchedCount

    return (
      <span className="font-mono text-xs tabular-nums">
        {role.watchedCount}
        {role.unwatchedCount > 0 && <span className="text-subtle"> sur {total}</span>}
        <span className="text-subtle"> {workUnit(role.role, total)} dans ta bibliothèque</span>
      </span>
    )
  }

  const share = filmography.totalCount === 0 ? 0 : filmography.watchedCount / filmography.totalCount

  return (
    <div className="flex min-w-40 flex-col gap-1.5">
      <span className="font-mono text-xs tabular-nums">
        {filmography.watchedCount}
        <span className="text-subtle">
          {' '}
          sur {filmography.totalCount} {workUnit(role.role, filmography.totalCount)}
        </span>
      </span>
      <span className="block h-1.5 w-full bg-ink/10" role="presentation">
        <span className="block h-full bg-accent" style={{ width: `${share * 100}%` }} />
      </span>
    </div>
  )
}

function WatchlistStrip({ works }: { works: PersonWork[] }) {
  return (
    <section>
      <h2 className="font-serif text-2xl font-black tracking-tight">Ce qui t'attend</h2>
      <p className="mt-1 font-mono text-[11px] uppercase tracking-widest text-subtle">
        Ses œuvres déjà dans ta watchlist
      </p>

      <div className="mt-4 grid grid-cols-3 gap-4 sm:grid-cols-4 lg:grid-cols-6">
        {works.map((work) => (
          <WorkCard key={work.movieId} work={work} />
        ))}
      </div>
    </section>
  )
}

/**
 * What TMDB credits them with that has not been watched.
 *
 * Flattened across jobs and deduplicated: a film somebody wrote *and* directed is one film
 * left to see, not two, and listing it under both headings would say otherwise.
 */
function StillToSee({ filmography }: { filmography: PersonFilmography }) {
  const missing = new Map<number, { entry: FilmographyRole['missing'][number]; roles: FilmographyRole['role'][] }>()

  for (const role of filmography.roles) {
    for (const entry of role.missing) {
      const existing = missing.get(entry.tmdbId)
      if (existing) {
        existing.roles.push(role.role)
      } else {
        missing.set(entry.tmdbId, { entry, roles: [role.role] })
      }
    }
  }

  const entries = [...missing.values()]
  if (entries.length === 0) return null

  return (
    <section>
      <h2 className="font-serif text-2xl font-black tracking-tight">Il te reste à voir</h2>
      <p className="mt-1 font-mono text-[11px] uppercase tracking-widest text-subtle">
        Absent de ta bibliothèque, ou présent mais jamais vu
      </p>

      <div className="mt-4 grid grid-cols-3 gap-4 sm:grid-cols-4 lg:grid-cols-6">
        {entries.map(({ entry, roles }) => (
          // Nothing to link to: by definition none of these is a work this library holds
          // and has seen, so the card is a card and not a way anywhere.
          <div key={entry.tmdbId} className="border border-ink bg-paper">
            <div className="aspect-2/3 w-full overflow-hidden bg-surface-2">
              {entry.posterUrl ? (
                <img
                  src={entry.posterUrl}
                  alt={entry.title}
                  loading="lazy"
                  className="h-full w-full object-cover opacity-60 grayscale"
                />
              ) : (
                <div className="flex h-full w-full items-center justify-center bg-[radial-gradient(currentColor_1px,transparent_1px)] bg-size-[16px_16px] text-ink/10" />
              )}
            </div>
            <div className="border-t border-ink p-2">
              <p className="truncate font-serif text-xs font-bold leading-tight" title={entry.title}>
                {entry.title}
              </p>
              <p className="mt-1 truncate font-mono text-[10px] text-subtle">
                {entry.releaseYear ?? '—'} · {roles.map((role) => ROLE_LABEL[role]).join(', ')}
              </p>
            </div>
          </div>
        ))}
      </div>
    </section>
  )
}

function Works({ person }: { person: PersonProfile }) {
  if (person.works.length === 0) {
    return (
      <EmptyState
        title="Rien de lui·elle dans ta bibliothèque"
        description="Cette personne est créditée sur une œuvre que tu as importée, mais elle n'apparaît nulle part ailleurs."
      />
    )
  }

  return (
    <section>
      <div className="flex flex-wrap items-end justify-between gap-2">
        <div>
          <h2 className="font-serif text-2xl font-black tracking-tight">Dans ta bibliothèque</h2>
          <p className="mt-1 font-mono text-[11px] uppercase tracking-widest text-subtle">
            {person.works.length} œuvre{person.works.length > 1 ? 's' : ''}, de la plus récente à la plus ancienne
          </p>
        </div>
        {/* The old behaviour, kept as one link rather than as the only thing a name did. */}
        <Link
          to={`/movies?personId=${person.id}`}
          className="font-mono text-xs uppercase tracking-widest text-accent underline decoration-2 underline-offset-4 hover:no-underline"
        >
          Filtrer la bibliothèque
        </Link>
      </div>

      <div className="mt-4 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
        {person.works.map((work) => (
          <WorkCard key={work.movieId} work={work} />
        ))}
      </div>
    </section>
  )
}

/**
 * A work of theirs, carrying what they did on it.
 *
 * The roles ride on the card rather than splitting the grid into one section per job: a
 * person who wrote, directed and starred in the same film would otherwise appear three
 * times, which is the very confusion this page was built to end.
 */
function WorkCard({ work }: { work: PersonWork }) {
  return (
    <Link to={`/movies/${work.movieId}`} className="hard-shadow-hover group block border border-ink bg-paper">
      <div className="relative aspect-2/3 w-full overflow-hidden bg-surface-2">
        {work.posterUrl ? (
          <img
            src={work.posterUrl}
            alt={work.title}
            loading="lazy"
            className={`h-full w-full object-cover transition-all duration-300 group-hover:grayscale-0 group-hover:sepia-[.5] ${
              work.watched ? 'grayscale' : 'opacity-60 grayscale'
            }`}
          />
        ) : (
          <div className="flex h-full w-full items-center justify-center bg-[radial-gradient(currentColor_1px,transparent_1px)] bg-size-[16px_16px] text-ink/10">
            <span className="bg-paper px-2 font-mono text-[10px] uppercase tracking-widest text-subtle">
              Pas d'affiche
            </span>
          </div>
        )}
        {!work.watched && (
          <Badge variant="solid" className="absolute left-2 top-2">
            {work.inWatchlist ? 'Watchlist' : 'Jamais vu'}
          </Badge>
        )}
        {work.mediaType === 'series' && (
          <Badge variant="solid" className="absolute right-2 top-2">
            Série
          </Badge>
        )}
      </div>
      <div className="border-t border-ink p-3">
        <p className="truncate font-serif text-sm font-bold leading-tight group-hover:text-accent" title={work.title}>
          {work.title}
        </p>
        <p className="mt-1 truncate font-mono text-[10px] uppercase tracking-widest text-subtle">
          {work.roles.map((role) => ROLE_LABEL[role]).join(' · ')}
        </p>
        {work.characterName && (
          <p className="mt-0.5 truncate font-body text-[11px] italic text-subtle" title={work.characterName}>
            {work.characterName}
          </p>
        )}
        <div className="mt-1.5 flex items-center justify-between font-mono text-[11px] text-subtle">
          <span>{work.releaseYear ?? '—'}</span>
          <StarRating rating={work.myAverageRating} />
        </div>
      </div>
    </Link>
  )
}
