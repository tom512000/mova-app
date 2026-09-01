import type { CSSProperties } from 'react'
import { cn } from '@/utils/cn'

/**
 * Loading placeholders, shaped like the thing they stand in for.
 *
 * Two problems are being fixed here. The first is that they were invisible: the tone was
 * `--surface`, which on the paper edition is #f5f5f5 against a #f9f9f7 page — a contrast ratio
 * of 1.03, where 1.00 is the same colour. It was pulsing away where nobody could see it.
 *
 * Everything below is drawn in `ink/15` instead: the page's own ink at fifteen percent, which
 * lands at 1.38 on paper and 1.49 at night. Picking the ink rather than a fixed grey is what
 * keeps those two close — a literal tone would have to be chosen twice and would drift apart
 * the moment either palette moved. Fifteen is where the pulse is legible without the
 * placeholder starting to look like real content: its trough still sits near 1.17.
 *
 * The second is that one four-column grid of small cards was standing in for everything —
 * a poster wall, a 280-pixel chart, a film's whole detail page. A placeholder that does not
 * match what follows makes the page jump when the data lands, which is worse than no
 * placeholder at all: it reads as a layout bug rather than as loading. So each of these
 * mirrors a real component, down to the grid columns and the border it will be replaced by.
 */
export function Skeleton({ className, style }: { className?: string; style?: CSSProperties }) {
  return (
    <div
      // Reduced motion is respected explicitly: Tailwind's animate-pulse does not do it on
      // its own, and a page of things throbbing is exactly what that setting is asking about.
      className={cn('animate-pulse bg-ink/15 motion-reduce:animate-none', className)}
      style={style}
      aria-hidden
    />
  )
}

/** A stack of text lines, narrowing like real prose does. */
export function SkeletonLines({ count = 3, className }: { count?: number; className?: string }) {
  const widths = ['w-full', 'w-11/12', 'w-4/5', 'w-full', 'w-2/3']

  return (
    <div className={cn('flex flex-col gap-2', className)}>
      {Array.from({ length: count }, (_, index) => (
        <Skeleton key={index} className={cn('h-3', widths[index % widths.length])} />
      ))}
    </div>
  )
}

/**
 * The masthead every page opens on: an oversized serif title over a four-pixel rule. Drawn
 * because it is the one element common to all of them, and the one whose absence makes a
 * loading page look like a broken one.
 */
export function SkeletonPageHeader({ withSubtitle = true }: { withSubtitle?: boolean }) {
  return (
    <div className="border-b-4 border-ink pb-6">
      <Skeleton className="h-11 w-72 max-w-full sm:h-14" />
      {withSubtitle && <Skeleton className="mt-3 h-3.5 w-96 max-w-full" />}
    </div>
  )
}

/** Matches StatCard: bordered, a mono label, a large tabular figure. */
export function SkeletonStatCard() {
  return (
    <div className="border border-ink bg-paper p-5">
      <Skeleton className="h-2.5 w-24" />
      <Skeleton className="mt-2.5 h-8 w-28" />
    </div>
  )
}

/** The dashboard's summary row — the same columns the real cards use. */
export function SkeletonStatGrid({ count = 8 }: { count?: number }) {
  return (
    <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
      {Array.from({ length: count }, (_, index) => (
        <SkeletonStatCard key={index} />
      ))}
    </div>
  )
}

/** Matches MovieCard: a 2:3 poster, a rule, then the title and the year-and-stars row. */
export function SkeletonMovieCard() {
  return (
    <div className="border border-ink bg-paper">
      <Skeleton className="aspect-2/3 w-full" />
      <div className="border-t border-ink p-3">
        <Skeleton className="h-3.5 w-4/5" />
        <div className="mt-1.5 flex items-center justify-between">
          <Skeleton className="h-2.5 w-8" />
          <Skeleton className="h-2.5 w-16" />
        </div>
      </div>
    </div>
  )
}

/** The library and watchlist grids — six across on a wide screen, exactly like the real one. */
export function SkeletonMovieGrid({ count = 12 }: { count?: number }) {
  return (
    <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6">
      {Array.from({ length: count }, (_, index) => (
        <SkeletonMovieCard key={index} />
      ))}
    </div>
  )
}

/**
 * A bar chart at its real height, so the section does not resize when Recharts takes over.
 * The heights are a fixed pattern rather than random: a placeholder that reshuffles itself
 * on every re-render draws the eye to the wrong thing.
 */
const BAR_HEIGHTS = [46, 72, 58, 88, 64, 96, 52, 78, 68, 84, 44, 74, 62, 90]

export function SkeletonChart({ height = 280, bars = 12 }: { height?: number; bars?: number }) {
  return (
    <div style={{ height }} className="flex w-full flex-col">
      <div className="flex flex-1 items-end gap-2">
        {Array.from({ length: bars }, (_, index) => (
          <Skeleton
            key={index}
            className="flex-1"
            style={{ height: `${BAR_HEIGHTS[index % BAR_HEIGHTS.length]}%` }}
          />
        ))}
      </div>
      {/* The axis the bars stand on, drawn solid: it is the one part of a chart that is
          never uncertain, so it has no business pulsing. */}
      <div className="mt-2 h-px w-full bg-ink/25" />
      <div className="mt-2 flex gap-2">
        {Array.from({ length: bars }, (_, index) => (
          <div key={index} className="flex flex-1 justify-center">
            <Skeleton className="h-2 w-6" />
          </div>
        ))}
      </div>
    </div>
  )
}

/** The country ring and its legend, side by side as they end up. */
export function SkeletonDonut() {
  return (
    <div className="flex flex-col items-center gap-6 sm:flex-row sm:items-start">
      {/* Not a circle: the whole interface is built from square corners, and the border
          radius is forced to zero globally anyway — a rounded placeholder could not survive
          to be drawn. A square standing in for the ring keeps the footprint honest. */}
      <Skeleton className="size-52 shrink-0" />
      <div className="flex w-full flex-col gap-3">
        {Array.from({ length: 6 }, (_, index) => (
          <div key={index} className="flex items-center gap-3">
            <Skeleton className="size-3 shrink-0" />
            <Skeleton className="h-3 flex-1" style={{ maxWidth: `${88 - index * 11}%` }} />
          </div>
        ))}
      </div>
    </div>
  )
}

/** The four "most-watched people" panels: name in serif, a mono line of counts under it. */
export function SkeletonPersonGrid({ count = 6 }: { count?: number }) {
  return (
    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
      {Array.from({ length: count }, (_, index) => (
        <div key={index} className="border border-ink/30 p-4">
          <Skeleton className="h-5 w-2/3" />
          <Skeleton className="mt-1.5 h-3 w-1/2" />
        </div>
      ))}
    </div>
  )
}

/** The rhythm section: the year of days, then the weekday chart under it. */
export function SkeletonHeatmap() {
  return (
    <div className="mt-5 flex flex-col gap-8">
      <div className="flex gap-1 overflow-hidden">
        {Array.from({ length: 53 }, (_, week) => (
          <div key={week} className="flex flex-col gap-1">
            {Array.from({ length: 7 }, (_, day) => (
              <Skeleton key={day} className="size-2.75 shrink-0" />
            ))}
          </div>
        ))}
      </div>
      <div>
        <Skeleton className="mb-2 h-2.5 w-32" />
        <SkeletonChart height={240} bars={7} />
      </div>
    </div>
  )
}

/** "Vus à leur sortie": a headline count, a line of prose, then a strip of posters. */
export function SkeletonReleaseWindow() {
  return (
    <div className="flex flex-col gap-4">
      <Skeleton className="h-9 w-24" />
      <SkeletonLines count={2} />
      <div className="flex gap-3">
        {Array.from({ length: 4 }, (_, index) => (
          <Skeleton key={index} className="aspect-2/3 w-full" />
        ))}
      </div>
    </div>
  )
}

/** A film's page: the backdrop band, the poster column, the title and credits beside it. */
export function SkeletonMovieDetail() {
  return (
    <div className="flex flex-col gap-8">
      <Skeleton className="h-3 w-32" />
      <Skeleton className="-mx-4 h-64 sm:h-80" />

      <div className="grid grid-cols-1 gap-8 lg:grid-cols-12">
        <div className="lg:col-span-4">
          <Skeleton className="aspect-2/3 w-full border border-ink" />
          <Skeleton className="mt-2 h-2.5 w-40" />
        </div>

        <div className="flex flex-col gap-6 lg:col-span-8">
          <div>
            <Skeleton className="h-10 w-3/4 sm:h-12" />
            <Skeleton className="mt-2 h-3.5 w-1/2" />
          </div>
          {/* The badge row: type, runtime, genres, countries — a queue of short pills. */}
          <div className="flex flex-wrap gap-2">
            {[16, 12, 20, 14, 18, 11].map((width, index) => (
              <Skeleton key={index} className="h-5" style={{ width: `${width * 4}px` }} />
            ))}
          </div>
          <SkeletonLines count={4} className="max-w-2xl" />
          <Skeleton className="h-3.5 w-64" />
          <Skeleton className="h-3.5 w-80 max-w-full" />
        </div>
      </div>

      <div className="border border-ink p-5 sm:p-6">
        <Skeleton className="h-6 w-56" />
        <div className="mt-4 flex flex-col gap-4">
          {Array.from({ length: 2 }, (_, index) => (
            <div key={index} className="flex items-center justify-between gap-4 border-t border-ink/15 pt-4 first:border-0 first:pt-0">
              <Skeleton className="h-3.5 w-40" />
              <Skeleton className="h-3.5 w-24" />
            </div>
          ))}
        </div>
      </div>
    </div>
  )
}

/** Suggestion rows in the film search: the thumbnail, the title, the year. */
export function SkeletonSuggestions({ count = 3 }: { count?: number }) {
  return (
    <div className="flex flex-col">
      {Array.from({ length: count }, (_, index) => (
        <div key={index} className="flex items-center gap-3 px-3 py-2">
          <Skeleton className="h-12 w-8 shrink-0" />
          <Skeleton className="h-3.5 flex-1" style={{ maxWidth: `${72 - index * 14}%` }} />
          <Skeleton className="h-2.5 w-8 shrink-0" />
        </div>
      ))}
    </div>
  )
}

/** Matches ImportBatchRow: the filename and its status pill, the progress bar, the tallies. */
export function SkeletonImportRow() {
  return (
    <div className="border border-ink p-4">
      <div className="flex items-center justify-between gap-3">
        <Skeleton className="h-3.5 w-48 max-w-[60%]" />
        <Skeleton className="h-5 w-20 shrink-0" />
      </div>
      <Skeleton className="mt-3 h-1.5 w-full" />
      <Skeleton className="mt-2 h-2.5 w-72 max-w-full" />
    </div>
  )
}

/**
 * The import history. Three rows rather than one: the section is a list, and a single row
 * would promise a shorter page than the one that usually arrives.
 */
export function SkeletonImportHistory({ count = 3 }: { count?: number }) {
  return (
    <div className="flex flex-col gap-3">
      {Array.from({ length: count }, (_, index) => (
        <SkeletonImportRow key={index} />
      ))}
    </div>
  )
}

/** The RSS panel: heading, the explanation under it, the button, and the last-run line. */
export function SkeletonSyncPanel() {
  return (
    <div className="border border-ink p-5 sm:p-6">
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div className="min-w-0 flex-1">
          <Skeleton className="h-6 w-56 max-w-full" />
          <Skeleton className="mt-2 h-3 w-64 max-w-full" />
          <Skeleton className="mt-2 h-3 w-full max-w-md" />
        </div>
        <Skeleton className="h-11 w-52 shrink-0" />
      </div>
      <div className="mt-4 flex items-center gap-3">
        <Skeleton className="h-5 w-12 shrink-0" />
        <Skeleton className="h-3 w-64 max-w-full" />
      </div>
    </div>
  )
}

/**
 * A game board before its first deal. All eight share a shape — a bordered table with the
 * puzzle in it and the move underneath — so one placeholder covers them, sized by whichever
 * board it is standing in for.
 */
export function SkeletonGameBoard({ height = 320 }: { height?: number }) {
  return (
    <div className="flex flex-col gap-8">
      <div className="border border-ink p-5 sm:p-6">
        <Skeleton className="h-6 w-40" />
        <Skeleton className="mt-4 w-full" style={{ height }} />
      </div>
      {/* The move: a search box and its button, or the row of keys. */}
      <div className="flex gap-3">
        <Skeleton className="h-11 flex-1" />
        <Skeleton className="h-11 w-28 shrink-0" />
      </div>
    </div>
  )
}

/** The duel: the streak board, then the two films on the table. */
export function SkeletonDuelTable() {
  return (
    <div className="flex flex-col gap-8">
      <div className="flex items-stretch divide-x divide-ink border border-ink">
        {Array.from({ length: 2 }, (_, index) => (
          <div key={index} className="flex-1 px-5 py-4">
            <Skeleton className="h-2.5 w-24" />
            <Skeleton className="mt-2 h-9 w-12" />
          </div>
        ))}
      </div>
      <div className="grid grid-cols-2 gap-3 sm:gap-6">
        {Array.from({ length: 2 }, (_, index) => (
          <div key={index} className="border border-ink">
            <Skeleton className="aspect-2/3 w-full" />
            <div className="p-3 sm:p-4">
              <Skeleton className="h-5 w-3/4" />
              <Skeleton className="mt-1.5 h-3 w-12" />
            </div>
          </div>
        ))}
      </div>
    </div>
  )
}

/** The chronology: five posters dealt in a strip, waiting to be dragged into order. */
export function SkeletonTimelineStrip() {
  return (
    <div className="border border-ink p-5 sm:p-6">
      <Skeleton className="h-2.5 w-40" />
      <div className="mt-4 flex gap-2 sm:gap-3">
        {Array.from({ length: 5 }, (_, index) => (
          <div key={index} className="min-w-0 flex-1">
            <Skeleton className="aspect-2/3 w-full border border-ink" />
            <Skeleton className="mx-auto mt-1 h-2.5 w-3/4" />
          </div>
        ))}
      </div>
      <Skeleton className="mt-5 h-11 w-44" />
    </div>
  )
}

/** The museum wall: three rows of posters receding to the right. */
export function SkeletonMuseumWall() {
  return (
    <div className="flex h-[62vh] flex-col justify-center gap-3 overflow-hidden border border-ink p-6">
      {Array.from({ length: 3 }, (_, row) => (
        <div key={row} className="flex gap-3">
          {Array.from({ length: 10 }, (_, column) => (
            <Skeleton key={column} className="aspect-2/3 h-40 shrink-0" />
          ))}
        </div>
      ))}
    </div>
  )
}

/** The Letterboxd profile card: an identity line, a few figures, four pinned posters. */
export function SkeletonProfilePanel() {
  return (
    <div className="mt-5 flex flex-col gap-5">
      <Skeleton className="h-5 w-48" />
      <SkeletonLines count={2} />
      <div className="grid grid-cols-4 gap-3">
        {Array.from({ length: 4 }, (_, index) => (
          <Skeleton key={index} className="aspect-2/3 w-full" />
        ))}
      </div>
    </div>
  )
}

/**
 * The fallback behind every lazy route. It cannot know which page is coming, so it draws the
 * one thing they all open with — the masthead — and a neutral body under it. Anything more
 * specific would be a guess that is wrong seven times out of eight.
 */
export function SkeletonPage() {
  return (
    <div className="flex flex-col gap-8">
      <SkeletonPageHeader />
      <SkeletonStatGrid count={4} />
      <Skeleton className="h-64 w-full border border-ink" />
    </div>
  )
}
