import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { fetchRetrospective } from '@/services/retrospectiveService'
import { SkeletonPage } from '@/components/Skeleton'
import { ErrorState } from '@/components/ErrorState'
import { EmptyState } from '@/components/EmptyState'
import { PageMeta } from '@/components/PageMeta'
import { StatCard } from '@/components/StatCard'
import { Badge } from '@/components/ui/Badge'
import { StarRating } from '@/components/ui/StarRating'
import { formatMinutesAsDays, formatRating } from '@/utils/format'
import { ROLE_LABEL } from '@/utils/roles'
import { cn } from '@/utils/cn'
import type { Retrospective, RetrospectiveWork } from '@/types/api'

const MONTHS = [
  'janvier', 'février', 'mars', 'avril', 'mai', 'juin',
  'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre',
]

/**
 * The end-of-year ritual, in local form.
 *
 * The shapes it needs mostly existed — the rhythm card knew how to find a run of consecutive
 * days, the rankings knew how to count people and genres — but every one of those services
 * reports on the library entire, which is the opposite of what a retrospective is. So this is
 * a page over its own year-scoped service rather than a rearrangement of the dashboard's.
 *
 * Every section can be absent. A year with four viewings has no month that stands out and no
 * genre that took over, and the page has to read as a quiet year rather than a broken one.
 */
export function RetrospectivePage() {
  const [year, setYear] = useState<number | undefined>(undefined)

  const { data, isLoading, isError, error } = useQuery({
    queryKey: ['retrospective', year ?? 'latest'],
    queryFn: () => fetchRetrospective(year),
    // A closed year never changes, and the current one changes by one film at a time.
    staleTime: 5 * 60 * 1000,
  })

  if (isLoading) return <SkeletonPage />
  if (isError || !data) return <ErrorState message={(error as Error)?.message} />

  if (data.availableYears.length === 0 || !data.retrospective) {
    return (
      <div className="flex flex-col gap-8">
        <PageMeta title="Rétrospective" />
        <Masthead year={null} years={[]} onSelect={setYear} />
        <EmptyState
          title="Rien à raconter pour l'instant"
          description="La rétrospective se construit sur les visionnages datés. Importe ton export Letterboxd, ou attends d'avoir loggé quelques films au journal."
        />
      </div>
    )
  }

  const retrospective = data.retrospective

  return (
    <div className="flex flex-col gap-10">
      <PageMeta title={`Rétrospective ${retrospective.year}`} />

      <Masthead year={retrospective.year} years={data.availableYears} onSelect={setYear} />

      <Figures retrospective={retrospective} />

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <BusiestMonth retrospective={retrospective} />
        <LongestStreak retrospective={retrospective} />
        <GenreOfTheYear retrospective={retrospective} />
        <OldestDiscovery retrospective={retrospective} />
      </div>

      <PeopleOfTheYear retrospective={retrospective} />

      <TopRated retrospective={retrospective} />
    </div>
  )
}

/**
 * The year is the headline, so it is set as one — and the selector sits beside it rather than
 * above, because choosing the year is the same gesture as reading which one you are in.
 */
function Masthead({
  year,
  years,
  onSelect,
}: {
  year: number | null
  years: number[]
  onSelect: (year: number) => void
}) {
  return (
    <div className="flex flex-col gap-4 border-b-4 border-ink pb-6 sm:flex-row sm:items-end sm:justify-between">
      <div>
        <p className="font-mono text-[11px] uppercase tracking-widest text-subtle">Ta rétrospective</p>
        <h1 className="text-balance font-serif text-6xl font-black leading-none tracking-tighter tabular-nums sm:text-8xl">
          {year ?? '—'}
        </h1>
      </div>

      {years.length > 1 && (
        <div className="flex flex-wrap gap-2" role="group" aria-label="Choisir l'année">
          {years.map((candidate) => (
            <button
              key={candidate}
              type="button"
              onClick={() => onSelect(candidate)}
              aria-current={candidate === year ? 'true' : undefined}
              className={cn(
                'border px-3 py-1.5 font-mono text-xs tabular-nums uppercase tracking-widest transition-colors',
                candidate === year
                  ? 'border-ink bg-ink text-paper'
                  : 'border-ink/40 text-subtle hover:border-ink hover:text-ink'
              )}
            >
              {candidate}
            </button>
          ))}
        </div>
      )}
    </div>
  )
}

function Figures({ retrospective }: { retrospective: Retrospective }) {
  const previous = retrospective.previousYear
  const delta = previous === null ? null : retrospective.watchCount - previous.watchCount

  return (
    <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
      <StatCard
        label="Visionnages"
        value={retrospective.watchCount}
        hint={
          delta === null
            ? `${retrospective.workCount} œuvres différentes`
            : `${delta >= 0 ? '+' : '−'}${Math.abs(delta)} sur ${previous?.year}`
        }
      />
      <StatCard
        label="Temps passé"
        value={formatMinutesAsDays(retrospective.totalRuntimeMinutes)}
        // Said out loud rather than rounded away: a work with no runtime on TMDB contributes
        // nothing to the sum, so the figure is a floor and pretending otherwise would be a
        // small lie told confidently.
        hint={
          retrospective.worksWithoutRuntime > 0
            ? `au moins — ${retrospective.worksWithoutRuntime} œuvre${retrospective.worksWithoutRuntime > 1 ? 's' : ''} sans durée connue`
            : 'de film, bout à bout'
        }
      />
      <StatCard
        label="Jours de cinéma"
        value={retrospective.activeDays}
        hint={`sur les ${retrospective.year === new Date().getFullYear() ? 'jours écoulés' : '365 jours'}`}
      />
      <StatCard
        label="Ta note moyenne"
        value={formatRating(retrospective.averageRating)}
        hint={
          previous?.averageRating == null
            ? 'sur l’année'
            : `${previous.averageRating > (retrospective.averageRating ?? 0) ? 'plus sévère' : 'plus généreux'} qu’en ${previous.year}`
        }
      />
    </div>
  )
}

/** The shared frame every narrative block sits in, so they read as one series of cards. */
function Panel({
  label,
  children,
  empty,
}: {
  label: string
  children?: React.ReactNode
  empty?: string
}) {
  return (
    <section className="flex flex-col border border-ink bg-paper p-5 sm:p-6">
      <p className="font-mono text-[10px] uppercase tracking-widest text-subtle">{label}</p>
      {children ?? <p className="mt-3 font-body text-sm text-subtle">{empty}</p>}
    </section>
  )
}

function BusiestMonth({ retrospective }: { retrospective: Retrospective }) {
  const month = retrospective.busiestMonth

  return (
    <Panel label="Le mois le plus chargé" empty={month ? undefined : 'Pas assez de visionnages datés.'}>
      {month && (
        <>
          <p className="mt-2 font-serif text-4xl font-black capitalize tracking-tight">{MONTHS[month.month - 1]}</p>
          <p className="mt-2 font-mono text-sm tabular-nums">
            {month.watchCount} visionnages
            <span className="text-subtle"> · moyenne mensuelle {month.averageMonthCount}</span>
          </p>
          {/* The comparison as a shape, not only as a number: the bar is the month against
              the twelve-month average, which is what "le plus chargé" actually claims. */}
          <div className="mt-4 flex items-end gap-2" aria-hidden>
            <span className="block w-full bg-accent" style={{ height: 40 }} />
            <span
              className="block w-full bg-ink/15"
              style={{ height: Math.max(4, (month.averageMonthCount / month.watchCount) * 40) }}
            />
          </div>
          <p className="mt-1 flex justify-between font-mono text-[10px] uppercase tracking-widest text-subtle">
            <span>ce mois</span>
            <span>les autres</span>
          </p>
        </>
      )}
    </Panel>
  )
}

function LongestStreak({ retrospective }: { retrospective: Retrospective }) {
  const streak = retrospective.longestStreak

  return (
    <Panel label="La plus longue série" empty={streak ? undefined : 'Pas encore de jours consécutifs.'}>
      {streak && (
        <>
          <p className="mt-2 font-serif text-4xl font-black tracking-tight">
            <span className="tabular-nums">{streak.days}</span> jour{streak.days > 1 ? 's' : ''} d’affilée
          </p>
          {/* Dates and not only a count: "vingt-cinq jours" is a number, "du 8 août au
              1er septembre" is a memory, and the memory is what the page is for. */}
          <p className="mt-2 font-mono text-sm">
            du {formatDayAndMonth(streak.startDate)} au {formatDayAndMonth(streak.endDate)}
            <span className="text-subtle"> · {streak.watchCount} visionnages</span>
          </p>
        </>
      )}
    </Panel>
  )
}

function GenreOfTheYear({ retrospective }: { retrospective: Retrospective }) {
  const genre = retrospective.genre

  return (
    <Panel label="Le genre de l’année" empty={genre ? undefined : 'Aucun genre assez présent pour ressortir.'}>
      {genre && (
        <>
          <p className="mt-2 font-serif text-4xl font-black tracking-tight">{genre.genreName}</p>
          <p className="mt-2 font-mono text-sm tabular-nums">
            {genre.share}% de ton année
            <span className="text-subtle"> · {genre.watchCount} visionnages</span>
          </p>
          {genre.previousShare !== null && (
            <p className="mt-3 font-body text-sm">
              {genre.share >= genre.previousShare ? 'En hausse de ' : 'En baisse de '}
              <b className="tabular-nums">{Math.abs(genre.share - genre.previousShare).toFixed(1)} points</b> sur{' '}
              {retrospective.previousYear?.year}, où il pesait{' '}
              <span className="tabular-nums">{genre.previousShare}%</span>.
            </p>
          )}
          {/* Stated because the arithmetic invites the wrong reading: a film belongs to each
              of its genres, so these shares sum past 100 — the same convention the country
              ring already uses. */}
          <p className="mt-auto pt-4 font-mono text-[10px] uppercase tracking-widest text-subtle">
            Un film compte pour chacun de ses genres
          </p>
        </>
      )}
    </Panel>
  )
}

function OldestDiscovery({ retrospective }: { retrospective: Retrospective }) {
  const work = retrospective.oldestDiscovery

  return (
    <Panel
      label="Le plus vieux film découvert"
      empty={work ? undefined : 'Aucune première fois cette année.'}
    >
      {work && (
        <div className="mt-3 flex items-center gap-4">
          {work.posterUrl && (
            <img
              src={work.posterUrl}
              alt=""
              loading="lazy"
              className="h-28 w-20 shrink-0 border border-ink object-cover grayscale"
            />
          )}
          <div className="min-w-0">
            <p className="font-serif text-3xl font-black tabular-nums leading-none">{work.releaseYear}</p>
            <Link
              to={`/movies/${work.movieId}`}
              className="mt-2 block truncate font-serif text-lg font-bold underline-offset-4 decoration-accent decoration-2 hover:underline"
              title={work.title}
            >
              {work.title}
            </Link>
            {/* "Découvert", so a rewatch of an old favourite does not win this every year. */}
            <p className="mt-1 font-mono text-[10px] uppercase tracking-widest text-subtle">
              Vu pour la première fois
            </p>
          </div>
        </div>
      )}
    </Panel>
  )
}

function PeopleOfTheYear({ retrospective }: { retrospective: Retrospective }) {
  if (retrospective.people.length === 0) return null

  return (
    <section>
      <h2 className="font-serif text-2xl font-black tracking-tight">Tes gens de l’année</h2>
      <p className="mt-1 font-mono text-[11px] uppercase tracking-widest text-subtle">
        Direction et interprétation — les deux métiers pour lesquels on choisit un film
      </p>

      <div className="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
        {retrospective.people.map((person) => (
          <Link
            key={person.role}
            to={`/people/${person.personId}`}
            className="hard-shadow-hover flex items-center gap-4 border border-ink bg-paper p-4"
          >
            <div className="h-20 w-20 shrink-0 overflow-hidden border border-ink bg-surface-2">
              {person.profileUrl ? (
                <img src={person.profileUrl} alt="" loading="lazy" className="h-full w-full object-cover grayscale" />
              ) : (
                <div className="h-full w-full bg-[radial-gradient(currentColor_1px,transparent_1px)] bg-size-[16px_16px] text-ink/10" />
              )}
            </div>
            <div className="min-w-0">
              <Badge>{ROLE_LABEL[person.role]}</Badge>
              <p className="mt-2 truncate font-serif text-xl font-bold" title={person.name}>
                {person.name}
              </p>
              <p className="font-mono text-xs tabular-nums text-subtle">
                {person.workCount} œuvre{person.workCount > 1 ? 's' : ''} cette année
              </p>
            </div>
          </Link>
        ))}
      </div>
    </section>
  )
}

function TopRated({ retrospective }: { retrospective: Retrospective }) {
  if (retrospective.topRated.length === 0) return null

  return (
    <section>
      <h2 className="font-serif text-2xl font-black tracking-tight">Tes plus belles notes</h2>
      <p className="mt-1 font-mono text-[11px] uppercase tracking-widest text-subtle">
        {/* The best note given during the year, not an average across rewatches: a film
            adored in March is not demoted by a lukewarm second viewing in November. */}
        La meilleure note que tu as donnée à chacun, cette année-là
      </p>

      <div className="mt-4 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5">
        {retrospective.topRated.map((work, index) => (
          <TopRatedCard key={work.movieId} work={work} rank={index + 1} />
        ))}
      </div>
    </section>
  )
}

function TopRatedCard({ work, rank }: { work: RetrospectiveWork; rank: number }) {
  return (
    <Link to={`/movies/${work.movieId}`} className="hard-shadow-hover group block border border-ink bg-paper">
      <div className="relative aspect-2/3 w-full overflow-hidden bg-surface-2">
        {work.posterUrl ? (
          <img
            src={work.posterUrl}
            alt={work.title}
            loading="lazy"
            className="h-full w-full object-cover grayscale transition-all duration-300 group-hover:grayscale-0 group-hover:sepia-[.5]"
          />
        ) : (
          <div className="flex h-full w-full items-center justify-center bg-[radial-gradient(currentColor_1px,transparent_1px)] bg-size-[16px_16px] text-ink/10">
            <span className="bg-paper px-2 font-mono text-[10px] uppercase tracking-widest text-subtle">
              Pas d’affiche
            </span>
          </div>
        )}
        {/* The rank is the point of the list, so it sits on the artwork rather than under it. */}
        <span className="absolute left-0 top-0 bg-ink px-2 py-1 font-mono text-[11px] tabular-nums text-paper">
          {rank}
        </span>
      </div>
      <div className="border-t border-ink p-3">
        <p className="truncate font-serif text-sm font-bold leading-tight group-hover:text-accent" title={work.title}>
          {work.title}
        </p>
        <div className="mt-1.5 flex items-center justify-between font-mono text-[11px] text-subtle">
          <span className="tabular-nums">{work.releaseYear ?? '—'}</span>
          <StarRating rating={work.rating} />
        </div>
      </div>
    </Link>
  )
}

/** "8 août" — the year is already the headline, so repeating it on every date is noise. */
function formatDayAndMonth(isoDate: string): string {
  return new Date(isoDate).toLocaleDateString('fr-FR', { day: 'numeric', month: 'long' })
}
