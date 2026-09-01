import { Fragment, useMemo, useRef, useState, type KeyboardEvent } from 'react'
import { Link } from 'react-router-dom'
import type { ActivityDay } from '@/types/api'
import { formatCalendarDay } from '@/utils/format'
import { cn } from '@/utils/cn'

const WEEKDAY_LABELS = ['Lun', '', 'Mer', '', 'Ven', '', 'Dim']
const MONTH_LABELS = ['Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Juin', 'Juil', 'Août', 'Sep', 'Oct', 'Nov', 'Déc']

/** Below this a week column stops shrinking and the strip scrolls instead. */
const MIN_CELL_PX = 11

/**
 * Today's square, marked entirely inside its own box: the red border plus a 1px inset
 * shadow, which reads as a 2px frame. Drawn outwards — as a plain ring — it would be the
 * one pixel the scroll container clips off the last column, and today is often in it.
 */
const TODAY_MARKER = 'border-accent shadow-[inset_0_0_0_1px_var(--color-accent)]'

/**
 * A day you can open. Drawn inwards for the same reason today's marker is — the strip
 * scrolls, so anything outside the square gets clipped on the first and last columns — and
 * in paper rather than accent, which is spoken for.
 */
const CLICKABLE =
    'cursor-pointer hover:border-ink hover:shadow-[inset_0_0_0_2px_var(--color-paper)]'
    + ' focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-accent'

interface Cell {
  date: Date
  key: string
  count: number
}

/**
 * Calendar of viewing activity, one square per day, weeks running down each column.
 *
 * Built as a CSS grid rather than with the chart library: it needs square cells on a fixed
 * 7-row week and no axes at all, which every charting abstraction fights. Intensity is a
 * four-step ink opacity — the app's palette is monochrome by rule, so density is carried by
 * value rather than hue.
 *
 * The weekday labels and the month strip live in that same grid rather than in columns of
 * their own. They have to line up with rows whose height is decided by the cells, and the
 * only way to stay aligned with something that resizes is to be measured by the same grid.
 */
export function ActivityHeatmap({ data }: { data: ActivityDay[] }) {
  // Local date parts, not the UTC ones: west of Greenwich late in the evening, the UTC day
  // has already turned over and "today" would ring tomorrow's square.
  const today = new Date()
  const todayKey = `${today.getFullYear()}-${pad(today.getMonth() + 1)}-${pad(today.getDate())}`

  const { weeks, monthMarkers, maxCount } = useMemo(() => buildCalendar(data, todayKey), [data, todayKey])

  // The squares that lead somewhere, in the order they happened. A week column reads top to
  // bottom and the strip reads left to right, and both directions mean "later" — so one
  // chronological list serves all four arrow keys, and every step lands on a square that can
  // actually be opened rather than on one of the blanks between them.
  const days = useMemo(() => weeks.flat().filter((cell) => cell.count > 0), [weeks])

  const gridRef = useRef<HTMLDivElement>(null)
  const [focusKey, setFocusKey] = useState<string | null>(null)
  // One tab stop for the whole calendar. Without this every day you have ever watched a film
  // would be a stop of its own, and reaching the section below would take four hundred
  // presses. The last active day is the default: it is the end you were looking at.
  const rovingKey = days.some((cell) => cell.key === focusKey) ? focusKey : (days.at(-1)?.key ?? null)

  const moveFocus = (event: KeyboardEvent<HTMLAnchorElement>, key: string) => {
    const index = days.findIndex((cell) => cell.key === key)
    if (index < 0) return

    let target: number
    switch (event.key) {
      case 'ArrowRight':
      case 'ArrowDown':
        target = index + 1
        break
      case 'ArrowLeft':
      case 'ArrowUp':
        target = index - 1
        break
      case 'Home':
        target = 0
        break
      case 'End':
        target = days.length - 1
        break
      default:
        return
    }

    event.preventDefault()
    const next = days[Math.min(days.length - 1, Math.max(0, target))]
    if (!next) return
    setFocusKey(next.key)
    gridRef.current?.querySelector<HTMLAnchorElement>(`[data-day="${next.key}"]`)?.focus()
  }

  if (weeks.length === 0) {
    return <p className="font-mono text-xs text-subtle">Aucune activité à afficher.</p>
  }

  return (
    <div className="flex flex-col gap-1.5">
      <div className="overflow-x-auto">
        <div
          ref={gridRef}
          // The half-pixel each of seventy-odd 1fr tracks rounds up with has to go
          // somewhere, and against a scroll container that somewhere is the clipped edge —
          // which shaves the last column. The padding absorbs it, and on every side, since
          // a focus ring on an edge square would be clipped the same way.
          className="grid w-full gap-[3px] p-1"
          style={{
            // A week takes an equal share of whatever width the block has, so the last one
            // lands on the right edge. The floor is what makes it scroll on a narrow screen
            // instead of squeezing a year and a half into squares nobody can see.
            gridTemplateColumns: `auto repeat(${weeks.length}, minmax(${MIN_CELL_PX}px, 1fr))`,
          }}
        >
          <span aria-hidden />
          {weeks.map((week, index) => (
            <span
              key={week[0].key}
              className="overflow-visible whitespace-nowrap font-mono text-[9px] uppercase tracking-widest text-subtle"
            >
              {monthMarkers.get(index)}
            </span>
          ))}

          {WEEKDAY_LABELS.map((label, row) => (
            <Fragment key={row}>
              <span className="self-center pr-1 text-right font-mono text-[9px] uppercase tracking-widest text-subtle">
                {label}
              </span>
              {weeks.map((week) => {
                const cell = week[row]
                const dayLabel = `${formatCalendarDay(cell.key)} · ${cell.count} visionnage${
                  cell.count > 1 ? 's' : ''
                }${cell.key === todayKey ? " · aujourd'hui" : ''}`
                const box = cn(
                  // Height follows width, so the cells stay square at every size.
                  'aspect-square border',
                  intensityClass(cell.count, maxCount),
                  cell.key === todayKey && TODAY_MARKER
                )

                // A day with nothing on it stays a plain square: the link would lead to an
                // empty list, which is a worse answer than no link at all.
                if (cell.count === 0) {
                  return <span key={cell.key} title={dayLabel} className={box} />
                }

                return (
                  <Link
                    key={cell.key}
                    to={`/movies?watchedOn=${cell.key}`}
                    data-day={cell.key}
                    tabIndex={cell.key === rovingKey ? 0 : -1}
                    onFocus={() => setFocusKey(cell.key)}
                    onKeyDown={(event) => moveFocus(event, cell.key)}
                    title={dayLabel}
                    // The square carries no text, so the date and the tally have to be
                    // said out loud somewhere.
                    aria-label={dayLabel}
                    className={cn(box, CLICKABLE)}
                  />
                )
              })}
            </Fragment>
          ))}
        </div>
      </div>

      <div className="flex flex-wrap items-center gap-x-4 gap-y-1.5 pt-1 font-mono text-[9px] uppercase tracking-widest text-subtle">
        <span className="flex items-center gap-1.5">
          <span>Moins</span>
          <span className="h-[11px] w-[11px] border border-ink/15 bg-transparent" />
          <span className="h-[11px] w-[11px] border border-ink/20 bg-ink/25" />
          <span className="h-[11px] w-[11px] border border-ink/30 bg-ink/50" />
          <span className="h-[11px] w-[11px] border border-ink/40 bg-ink/75" />
          <span className="h-[11px] w-[11px] border border-ink bg-ink" />
          <span>Plus</span>
        </span>
        {/* Red is the only colour on an otherwise monochrome strip, so it needs saying
            once — and saying it also keeps the marker legible without relying on hue. */}
        <span className="flex items-center gap-1.5">
          <span className={cn('h-[11px] w-[11px] bg-transparent border', TODAY_MARKER)} />
          <span>Aujourd'hui</span>
        </span>
      </div>

      <p className="font-mono text-[10px] uppercase tracking-widest text-subtle">
        Clique un jour pour filtrer les films et séries
      </p>
    </div>
  )
}

function intensityClass(count: number, maxCount: number): string {
  if (count <= 0) return 'border-ink/15 bg-transparent'

  // Quartiles of the busiest day rather than absolute counts: a library with a two-film
  // maximum should still show contrast, and one with a fifteen-film day shouldn't saturate.
  const ratio = count / Math.max(1, maxCount)
  if (ratio <= 0.25) return 'border-ink/20 bg-ink/25'
  if (ratio <= 0.5) return 'border-ink/30 bg-ink/50'
  if (ratio <= 0.75) return 'border-ink/40 bg-ink/75'
  return 'border-ink bg-ink'
}

function pad(value: number): string {
  return String(value).padStart(2, '0')
}

/**
 * Lays the days out into ISO weeks (Monday first).
 *
 * Every slot of every column is a real square, including the days before the first watch
 * and the ones still ahead — an empty square reads as "nothing that day", which is exactly
 * what those are, whereas the gaps this used to leave read as a ragged edge. The strip runs
 * to the end of the week containing today so the current week is whole and today has a
 * square to be marked on.
 */
function buildCalendar(
  data: ActivityDay[],
  todayKey: string
): { weeks: Cell[][]; monthMarkers: Map<number, string>; maxCount: number } {
  if (data.length === 0) return { weeks: [], monthMarkers: new Map(), maxCount: 0 }

  const counts = new Map(data.map((day) => [day.date, day.watchCount]))
  const maxCount = Math.max(...data.map((day) => day.watchCount))

  // Parsed as UTC so a browser east or west of the server can't shift a day into its
  // neighbour and land it in the wrong column.
  const today = parseUtc(todayKey)
  const first = min(parseUtc(data[0].date), today)
  const last = max(parseUtc(data[data.length - 1].date), today)

  const start = new Date(first)
  start.setUTCDate(start.getUTCDate() - isoWeekdayIndex(start))

  const end = new Date(last)
  end.setUTCDate(end.getUTCDate() + (6 - isoWeekdayIndex(end)))

  const weeks: Cell[][] = []
  const monthMarkers = new Map<number, string>()
  let lastMonth = -1

  for (let cursor = new Date(start); cursor <= end; ) {
    const week: Cell[] = []

    for (let dayIndex = 0; dayIndex < 7; dayIndex++) {
      const date = new Date(cursor)
      const key = toIsoDate(date)
      week.push({ date, key, count: counts.get(key) ?? 0 })
      cursor.setUTCDate(cursor.getUTCDate() + 1)
    }

    // Label a column with its month only when the month changes, so the strip reads as a
    // timeline instead of repeating the same word a dozen times.
    if (week[0].date.getUTCMonth() !== lastMonth) {
      lastMonth = week[0].date.getUTCMonth()
      monthMarkers.set(weeks.length, MONTH_LABELS[lastMonth])
    }

    weeks.push(week)
  }

  return { weeks, monthMarkers, maxCount }
}

function parseUtc(isoDate: string): Date {
  return new Date(`${isoDate}T00:00:00Z`)
}

function toIsoDate(date: Date): string {
  return date.toISOString().slice(0, 10)
}

function min(a: Date, b: Date): Date {
  return a <= b ? a : b
}

function max(a: Date, b: Date): Date {
  return a >= b ? a : b
}

/** 0 for Monday through 6 for Sunday. */
function isoWeekdayIndex(date: Date): number {
  return (date.getUTCDay() + 6) % 7
}
