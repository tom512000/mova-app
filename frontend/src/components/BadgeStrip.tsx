import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { SectionLink } from '@/components/ui/SectionLink'
import { BADGE_SUMMARY_KEY, fetchBadgeSummary } from '@/services/badgesService'
import { useSession } from '@/hooks/useSession'
import { BadgeStamp } from '@/components/BadgeStamp'
import { Skeleton } from '@/components/Skeleton'
import { badgeTitle } from '@/utils/badges'

/** How many stamps fit on one line before the counter takes over. */
const SHOWN = 5

/**
 * The shelf, in one line, on the profile.
 *
 * It asks for five and the totals rather than the whole shelf, under the key the shelf page
 * also reads its figures from — so the two share one small response instead of putting fifty
 * kilobytes on a page that shows five stamps.
 *
 * The five shown are the five highest levels, because the backend already returns them in
 * that order — the strip is a boast, and a boast opens with its best.
 *
 * It stands down while another profile is being viewed. The shelf itself reports on the
 * viewed profile, like every other read in the app, which is what makes a shared profile's
 * badges worth sharing — but everything else on "Mon compte" is about the logged-in account,
 * and somebody else's achievements between your Letterboxd panel and your password form
 * would be the one thing on the page that was not yours.
 */
export function BadgeStrip() {
  const { isViewingOtherProfile } = useSession()

  const { data, isLoading } = useQuery({
    queryKey: BADGE_SUMMARY_KEY,
    queryFn: fetchBadgeSummary,
    staleTime: 5 * 60 * 1000,
    enabled: !isViewingOtherProfile,
  })

  if (isViewingOtherProfile) {
    return null
  }

  if (isLoading) {
    return (
      <section className="border border-ink p-5 sm:p-6">
        <Skeleton className="h-6 w-40" />
        <div className="mt-4 flex gap-3">
          {Array.from({ length: SHOWN }, (_, index) => (
            <Skeleton key={index} className="aspect-square w-16 shrink-0" />
          ))}
        </div>
      </section>
    )
  }

  if (!data || data.total === 0) {
    return null
  }

  const shown = data.items.slice(0, SHOWN)
  const rest = data.total - shown.length

  return (
    <section className="border border-ink p-5 sm:p-6">
      <div className="flex flex-wrap items-baseline justify-between gap-2">
        <h2 className="font-serif text-2xl font-bold">
          Badges <span className="font-mono text-base font-normal text-subtle">({data.total})</span>
        </h2>
        <SectionLink to="/badges">Tout voir</SectionLink>
      </div>

      <div className="mt-4 flex flex-wrap items-center gap-3">
        {shown.map((badge) => (
          <Link
            key={`${badge.category}-${badge.subjectId}`}
            to="/badges"
            className="hard-shadow-hover group block w-16 shrink-0"
            title={`${badgeTitle(badge)} · niveau ${badge.level}`}
          >
            <BadgeStamp badge={badge} size="sm" />
          </Link>
        ))}

        {rest > 0 && (
          // The "+43" of the reference, and a link rather than a label: it is the most
          // obvious thing to click on the whole strip.
          <Link
            to="/badges"
            className="hard-shadow-hover flex aspect-square w-16 shrink-0 items-center justify-center border border-ink bg-surface font-mono text-sm font-bold tabular-nums text-subtle transition-colors hover:text-accent"
          >
            +{rest}
          </Link>
        )}
      </div>
    </section>
  )
}
