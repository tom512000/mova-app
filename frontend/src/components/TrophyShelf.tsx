import { useQuery } from '@tanstack/react-query'
import {
  CalendarPlus,
  CalendarRange,
  Cat,
  Flag,
  Ghost,
  Gift,
  Hammer,
  Heart,
  Hourglass,
  Lock,
  PartyPopper,
  Rabbit,
  Repeat,
  Sofa,
  Undo2,
  type LucideIcon,
} from 'lucide-react'
import { fetchTrophies } from '@/services/trophiesService'
import { ErrorState } from '@/components/ErrorState'
import { Skeleton } from '@/components/Skeleton'
import { cn } from '@/utils/cn'
import { formatCalendarDay } from '@/utils/format'
import { TROPHY_COPY, TROPHY_FAMILIES } from '@/utils/trophies'
import type { Trophy, TrophyKey } from '@/types/api'

/**
 * An icon rather than a still from a film, which is what separates a trophy's stamp from a
 * badge's at a glance. A badge is about a subject that has pictures; "a film on the 14th of
 * February" has no film of its own to show.
 */
const ICONS: Record<TrophyKey, LucideIcon> = {
  groundhog_day: Repeat,
  weekends: Sofa,
  dirty_dozen: CalendarRange,
  return_of_the_jedi: Undo2,
  old_timers: Hourglass,
  christmas: Gift,
  new_year: PartyPopper,
  valentine: Heart,
  easter: Rabbit,
  labour_day: Hammer,
  bastille_day: Flag,
  halloween: Ghost,
  friday_the_13th: Cat,
  leap_day: CalendarPlus,
}

/**
 * The trophies, on a shelf of their own above the badges.
 *
 * Apart because they are a different kind of thing. Badges are generated — one for every
 * genre, country and person seen often enough, hundreds of them — and a trophy is one named
 * thing with a joke attached. On the same shelf, "Le Père Noël est une ordure" would sit on
 * page twelve behind actors seen five times.
 *
 * Locked trophies are drawn too, greyed, with what it takes. There are fourteen, and one not
 * yet won is more interesting as something to go and get than as a gap nobody can see. They
 * keep their place in the catalogue rather than sorting behind the won ones, so winning one
 * fills a slot on the shelf instead of reshuffling it.
 */
export function TrophyShelf() {
  const { data, isLoading, isError, error } = useQuery({
    queryKey: ['trophies'],
    queryFn: fetchTrophies,
    staleTime: 5 * 60 * 1000,
  })

  const won = data?.filter((trophy) => trophy.level > 0).length ?? 0

  return (
    <section className="flex flex-col gap-6">
      <div className="flex flex-wrap items-baseline justify-between gap-2 border-b border-ink pb-3">
        <h2 className="font-serif text-3xl font-black tracking-tight">Trophées</h2>
        {data && (
          <p className="font-mono text-xs uppercase tracking-widest text-subtle">
            <b className="text-ink">{won}</b> sur {data.length} obtenus
          </p>
        )}
      </div>

      {isLoading && <SkeletonTrophyGrid />}
      {isError && <ErrorState message={(error as Error).message} />}

      {data &&
        TROPHY_FAMILIES.map(({ family, label, hint }) => {
          const trophies = data.filter((trophy) => trophy.family === family)
          if (trophies.length === 0) return null

          return (
            <div key={family}>
              <h3 className="font-serif text-xl font-bold">{label}</h3>
              <p className="mt-0.5 font-mono text-[11px] uppercase tracking-widest text-subtle">{hint}</p>
              <div className="mt-3 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                {trophies.map((trophy) => (
                  <TrophyCard key={trophy.key} trophy={trophy} />
                ))}
              </div>
            </div>
          )
        })}
    </section>
  )
}

function TrophyCard({ trophy }: { trophy: Trophy }) {
  const copy = TROPHY_COPY[trophy.key]
  const won = trophy.level > 0
  const tiered = trophy.tiers.length > 1

  // The bar measures the climb to the next rung, from the rung already paid for. A trophy
  // won on a single day has no climb to draw — "0 / 1" is not a progress bar, it is a checkbox.
  const next = trophy.tiers[trophy.level] ?? null
  const floor = trophy.level > 0 ? trophy.tiers[trophy.level - 1] : 0
  const share = next === null ? 1 : Math.min(1, Math.max(0, (trophy.value - floor) / (next - floor)))

  return (
    <div className={cn('flex gap-4 border bg-paper p-4', won ? 'border-ink' : 'border-ink/30')}>
      <div className="w-16 shrink-0 sm:w-20">
        <TrophyStamp trophy={trophy} />
      </div>

      <div className="flex min-w-0 flex-1 flex-col">
        <p className={cn('font-serif text-lg font-bold leading-tight', !won && 'text-subtle')}>{copy.name}</p>
        <p className={cn('mt-1 text-xs', won ? 'font-body italic text-subtle' : 'text-subtle')}>
          {won ? copy.tagline : copy.condition}
        </p>

        <p className="mt-2 font-mono text-[11px] tabular-nums text-subtle">
          {copy.progress(trophy.value)}
          {tiered && won && <> &middot; niveau {trophy.level}</>}
        </p>

        {next !== null && next > 1 && (
          <div className="mt-auto pt-2">
            <span className="block h-1.5 w-full bg-ink/10" role="presentation">
              <span className="block h-full bg-accent" style={{ width: `${share * 100}%` }} />
            </span>
            <p className="mt-1.5 font-mono text-[10px] uppercase tracking-widest text-subtle">
              Prochain palier : {copy.rung(next)}
            </p>
          </div>
        )}

        {/* Read as UTC: earnedOn is a calendar day, and midnight UTC rendered west of
            Greenwich would name the evening before. */}
        {trophy.earnedOn !== null && (
          <p className="mt-1.5 font-mono text-[10px] text-subtle">
            {tiered ? `Niveau ${trophy.level} obtenu` : 'Obtenu'} le {formatCalendarDay(trophy.earnedOn)}
          </p>
        )}
      </div>
    </div>
  )
}

/**
 * Square, like a badge's stamp, because the stylesheet allows nothing else — but ink on
 * paper around an icon rather than a film still. A locked one is dashed and faded, with a
 * padlock where the level would go.
 */
function TrophyStamp({ trophy }: { trophy: Trophy }) {
  const Icon = ICONS[trophy.key]
  const won = trophy.level > 0

  return (
    <div
      className={cn(
        'relative flex aspect-square w-full items-center justify-center border',
        won ? 'border-ink bg-paper text-ink' : 'border-dashed border-ink/40 bg-surface text-ink/25'
      )}
    >
      <Icon className="h-1/2 w-1/2" strokeWidth={1.5} aria-hidden />

      {won && trophy.tiers.length > 1 && (
        <span
          className="absolute bottom-0 left-0 min-w-6 border-r border-t border-ink bg-ink px-1.5 py-0.5 text-center font-mono text-[11px] font-bold leading-none text-paper tabular-nums"
          title={`Niveau ${trophy.level}`}
        >
          {trophy.level}
        </span>
      )}

      {!won && (
        <span className="absolute bottom-1 right-1 text-ink/40" title="Pas encore obtenu">
          <Lock className="h-3 w-3" strokeWidth={2} aria-hidden />
        </span>
      )}
    </div>
  )
}

function SkeletonTrophyGrid() {
  return (
    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
      {Array.from({ length: 6 }, (_, index) => (
        <div key={index} className="flex gap-4 border border-ink/30 bg-paper p-4">
          <Skeleton className="aspect-square w-16 shrink-0 sm:w-20" />
          <div className="flex flex-1 flex-col gap-2">
            <Skeleton className="h-4 w-3/4" />
            <Skeleton className="h-3 w-full" />
            <Skeleton className="h-2.5 w-1/2" />
          </div>
        </div>
      ))}
    </div>
  )
}
