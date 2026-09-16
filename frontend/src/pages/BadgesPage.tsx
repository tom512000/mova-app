import { useQuery } from '@tanstack/react-query'
import { Link, useSearchParams } from 'react-router-dom'
import { ArrowLeft } from 'lucide-react'
import { BADGE_SUMMARY_KEY, fetchBadges, fetchBadgeSummary } from '@/services/badgesService'
import { BadgeStamp } from '@/components/BadgeStamp'
import { EmptyState } from '@/components/EmptyState'
import { ErrorState } from '@/components/ErrorState'
import { PageMeta } from '@/components/PageMeta'
import { Skeleton } from '@/components/Skeleton'
import { StatCard } from '@/components/StatCard'
import { Button } from '@/components/ui/Button'
import { FilterSelect, Option } from '@/components/ui/FilterSelect'
import { badgeHref, badgeTitle, BADGE_CATEGORIES, BADGE_CATEGORY_LABEL } from '@/utils/badges'
import { formatCalendarDay } from '@/utils/format'
import type { Badge, BadgeCategory } from '@/types/api'

const PER_PAGE = 48

/**
 * The shelf.
 *
 * Six hundred badges on a seven hundred work library, which is a lot — and the ladder is
 * what keeps that readable: the grid opens on the handful at level 7 and the long tail of
 * level 1 actors seen five times sits where a long tail belongs. The category filter is the
 * other half of it, since "all my genres" and "all my actors" are different questions.
 */
export function BadgesPage() {
  const [params, setParams] = useSearchParams()

  const category = (BADGE_CATEGORIES.find((known) => known === params.get('category')) ?? '') as BadgeCategory | ''
  const page = Math.max(1, Number(params.get('page') ?? 1))

  const { data, isLoading, isError, error } = useQuery({
    queryKey: ['badges', { category, page }],
    queryFn: () => fetchBadges({ category: category || undefined, page, perPage: PER_PAGE }),
    staleTime: 5 * 60 * 1000,
  })

  // The counts only come back on an unfiltered shelf, so they are asked for separately and
  // held: the dropdown must not lose its figures the moment it is used. Same key as the
  // strip on the profile, which has already paid for this response.
  const { data: whole } = useQuery({
    queryKey: BADGE_SUMMARY_KEY,
    queryFn: fetchBadgeSummary,
    staleTime: 5 * 60 * 1000,
  })

  const update = (patch: Record<string, string | null>) => {
    setParams(
      (previous) => {
        const next = new URLSearchParams(previous)
        for (const [key, value] of Object.entries(patch)) {
          if (value === null || value === '') next.delete(key)
          else next.set(key, value)
        }
        return next
      },
      { replace: true }
    )
  }

  const counts = whole?.counts ?? null
  const best = whole?.items[0] ?? null
  const totalPages = data ? Math.max(1, Math.ceil(data.total / data.perPage)) : 1

  return (
    <div className="flex flex-col gap-6">
      <PageMeta title="Badges" />

      <Link
        to="/account"
        className="inline-flex w-fit items-center gap-2 font-mono text-xs uppercase tracking-widest text-subtle hover:text-accent"
      >
        <ArrowLeft className="h-4 w-4" /> Mon compte
      </Link>

      <div className="flex flex-col gap-4 border-b-4 border-ink pb-6 sm:flex-row sm:items-end sm:justify-between">
        <div>
          <h1 className="text-balance font-serif text-5xl font-black tracking-tighter sm:text-6xl">Badges</h1>
          <p className="mt-2 font-mono text-[11px] uppercase tracking-widest text-subtle">
            Un premier niveau à 5 œuvres, puis un de plus à chaque fois que tu doubles
          </p>
        </div>
      </div>

      {whole && (
        <div className="grid grid-cols-2 gap-4 lg:grid-cols-3">
          <StatCard label="Badges obtenus" value={whole.total} hint="toutes catégories confondues" />
          <StatCard
            label="Meilleur niveau"
            value={best ? best.level : '—'}
            hint={best ? badgeTitle(best) : 'rien encore'}
          />
          <StatCard
            label="Catégories"
            value={counts ? Object.keys(counts).length : '—'}
            hint={`sur ${BADGE_CATEGORIES.length} possibles`}
            className="col-span-2 lg:col-span-1"
          />
        </div>
      )}

      <div className="flex flex-col gap-4 border border-ink p-4 sm:flex-row sm:items-end sm:justify-between">
        <FilterSelect
          label="Catégorie"
          value={category}
          onChange={(next) => update({ category: next, page: null })}
          className="sm:w-56"
        >
          <Option value="">Toutes</Option>
          {BADGE_CATEGORIES.map((known) => (
            <Option key={known} value={known}>
              {/* The tally rides in the option, so a category nobody has earned anything in
                  is visibly empty before it is chosen rather than after. */}
              {BADGE_CATEGORY_LABEL[known]}
              {counts ? ` (${counts[known] ?? 0})` : ''}
            </Option>
          ))}
        </FilterSelect>

        {data && (
          <p className="font-mono text-xs uppercase tracking-widest text-subtle">
            <b className="text-ink">{data.total}</b> badge{data.total > 1 ? 's' : ''}
            {category !== '' && <> &middot; {BADGE_CATEGORY_LABEL[category]}</>}
          </p>
        )}
      </div>

      {isLoading && <SkeletonBadgeGrid />}
      {isError && <ErrorState message={(error as Error).message} />}

      {data && data.items.length === 0 && (
        <EmptyState
          title="Aucun badge ici"
          description={
            category === ''
              ? 'Importe tes données Letterboxd : le premier badge tombe à cinq œuvres.'
              : 'Rien dans cette catégorie pour le moment. Il en faut cinq pour le premier niveau.'
          }
        />
      )}

      {data && data.items.length > 0 && (
        <>
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {data.items.map((badge) => (
              <BadgeCard key={`${badge.category}-${badge.subjectId}`} badge={badge} />
            ))}
          </div>

          <div className="flex items-center justify-center gap-4">
            <Button variant="secondary" size="sm" disabled={page <= 1} onClick={() => update({ page: String(page - 1) })}>
              Précédent
            </Button>
            <span className="font-mono text-xs uppercase tracking-widest text-subtle">
              Page {page} / {totalPages}
            </span>
            <Button
              variant="secondary"
              size="sm"
              disabled={page >= totalPages}
              onClick={() => update({ page: String(page + 1) })}
            >
              Suivant
            </Button>
          </div>
        </>
      )}
    </div>
  )
}

/**
 * One badge, with what the strip has no room for: what it counts, how far the next level is,
 * and the evening it was earned.
 *
 * The whole card is a link when the badge leads anywhere, and a plain div when it does not —
 * there is no listing for a country, a decade or a budget bracket, and a link to nothing is
 * worth less than plain text.
 */
function BadgeCard({ badge }: { badge: Badge }) {
  const href = badgeHref(badge)

  return href !== null ? (
    <Link to={href} className="hard-shadow-hover group flex gap-4 border border-ink bg-paper p-4">
      <BadgeBody badge={badge} />
    </Link>
  ) : (
    <div className="group flex gap-4 border border-ink bg-paper p-4">
      <BadgeBody badge={badge} />
    </div>
  )
}

function BadgeBody({ badge }: { badge: Badge }) {
  // How far into the current level the tally sits. The bar measures the climb still under
  // way, not the whole tally: the rung below is already paid for, and a bar that started
  // from zero would sit at 96% for somebody with four hundred comedies and never move.
  // Each rung costs what the one below it did, so the floor is simply half the ceiling.
  const ceiling = badge.workCount + badge.worksToNextLevel
  const floor = ceiling / 2
  const share = Math.min(1, Math.max(0, (badge.workCount - floor) / floor))

  return (
    <>
      <div className="w-20 shrink-0 sm:w-24">
        <BadgeStamp badge={badge} />
      </div>

      <div className="flex min-w-0 flex-1 flex-col">
        <p className="font-mono text-[10px] uppercase tracking-widest text-subtle">
          {BADGE_CATEGORY_LABEL[badge.category]}
        </p>
        <p className="truncate font-serif text-lg font-bold leading-tight group-hover:text-accent" title={badgeTitle(badge)}>
          {badgeTitle(badge)}
        </p>

        <p className="mt-1 font-mono text-xs tabular-nums text-subtle">
          {badge.workCount} œuvre{badge.workCount > 1 ? 's' : ''} &middot; niveau {badge.level}
        </p>

        <div className="mt-auto pt-2">
          <span className="block h-1.5 w-full bg-ink/10" role="presentation">
            <span className="block h-full bg-accent" style={{ width: `${share * 100}%` }} />
          </span>
          <p className="mt-1.5 font-mono text-[10px] uppercase tracking-widest text-subtle">
            +{badge.worksToNextLevel} pour le niveau {badge.level + 1}
          </p>
        </div>

        {/* Read as UTC rather than through formatDate: earnedOn is a calendar day, and
            rendering midnight UTC in a timezone west of Greenwich lands on the evening
            before — the badge would name the wrong day. */}
        {badge.earnedOn !== null && (
          <p className="mt-1.5 font-mono text-[10px] text-subtle">Obtenu le {formatCalendarDay(badge.earnedOn)}</p>
        )}
      </div>
    </>
  )
}

function SkeletonBadgeGrid() {
  return (
    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
      {Array.from({ length: 9 }, (_, index) => (
        <div key={index} className="flex gap-4 border border-ink bg-paper p-4">
          <Skeleton className="aspect-square w-20 shrink-0 sm:w-24" />
          <div className="flex flex-1 flex-col gap-2">
            <Skeleton className="h-2.5 w-16" />
            <Skeleton className="h-4 w-3/4" />
            <Skeleton className="h-2.5 w-24" />
            <Skeleton className="mt-auto h-1.5 w-full" />
            <Skeleton className="h-2.5 w-28" />
          </div>
        </div>
      ))}
    </div>
  )
}
