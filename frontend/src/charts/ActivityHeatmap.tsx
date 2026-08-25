import { useMemo } from 'react'
import type { ActivityDay } from '@/types/api'
import { cn } from '@/utils/cn'

const WEEKDAY_LABELS = ['Lun', '', 'Mer', '', 'Ven', '', 'Dim']
const MONTH_LABELS = ['Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Juin', 'Juil', 'Août', 'Sep', 'Oct', 'Nov', 'Déc']

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
 */
export function ActivityHeatmap({ data }: { data: ActivityDay[] }) {
  const { weeks, monthMarkers, maxCount } = useMemo(() => buildCalendar(data), [data])

  if (weeks.length === 0) {
    return <p className="font-mono text-xs text-subtle">Aucune activité à afficher.</p>
  }

  return (
    <div className="overflow-x-auto">
      <div className="inline-flex min-w-max flex-col gap-1.5">
        <div className="flex gap-[3px] pl-8 font-mono text-[9px] uppercase tracking-widest text-subtle">
          {weeks.map((week, index) => (
            <span key={week[0]?.key ?? index} className="w-[11px] shrink-0">
              {monthMarkers.get(index)}
            </span>
          ))}
        </div>

        <div className="flex gap-[3px]">
          <div className="flex w-8 shrink-0 flex-col gap-[3px] pr-1 text-right font-mono text-[9px] uppercase tracking-widest text-subtle">
            {WEEKDAY_LABELS.map((label, index) => (
              <span key={index} className="h-[11px] leading-[11px]">
                {label}
              </span>
            ))}
          </div>

          {weeks.map((week, weekIndex) => (
            <div key={week[0]?.key ?? weekIndex} className="flex flex-col gap-[3px]">
              {week.map((cell, dayIndex) =>
                cell === null ? (
                  <span key={dayIndex} className="h-[11px] w-[11px]" />
                ) : (
                  <span
                    key={cell.key}
                    title={`${formatDay(cell.date)} · ${cell.count} visionnage${cell.count > 1 ? 's' : ''}`}
                    className={cn('h-[11px] w-[11px] border', intensityClass(cell.count, maxCount))}
                  />
                )
              )}
            </div>
          ))}
        </div>

        <div className="flex items-center gap-1.5 pl-8 pt-1 font-mono text-[9px] uppercase tracking-widest text-subtle">
          <span>Moins</span>
          <span className="h-[11px] w-[11px] border border-ink/15 bg-transparent" />
          <span className="h-[11px] w-[11px] border border-ink/20 bg-ink/25" />
          <span className="h-[11px] w-[11px] border border-ink/30 bg-ink/50" />
          <span className="h-[11px] w-[11px] border border-ink/40 bg-ink/75" />
          <span className="h-[11px] w-[11px] border border-ink bg-ink" />
          <span>Plus</span>
        </div>
      </div>
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

function formatDay(date: Date): string {
  return date.toLocaleDateString('fr-FR', { day: 'numeric', month: 'long', year: 'numeric' })
}

/**
 * Lays the days out into ISO weeks (Monday first), padding the first and last week so every
 * column holds exactly seven slots.
 */
function buildCalendar(data: ActivityDay[]): {
  weeks: (Cell | null)[][]
  monthMarkers: Map<number, string>
  maxCount: number
} {
  if (data.length === 0) return { weeks: [], monthMarkers: new Map(), maxCount: 0 }

  const counts = new Map(data.map((day) => [day.date, day.watchCount]))
  const maxCount = Math.max(...data.map((day) => day.watchCount))

  // Parsed as UTC so a browser east or west of the server can't shift a day into its
  // neighbour and land it in the wrong column.
  const first = parseUtc(data[0].date)
  const last = parseUtc(data[data.length - 1].date)

  const start = new Date(first)
  start.setUTCDate(start.getUTCDate() - isoWeekdayIndex(start))

  const weeks: (Cell | null)[][] = []
  const monthMarkers = new Map<number, string>()
  let lastMonth = -1

  for (let cursor = new Date(start); cursor <= last; ) {
    const week: (Cell | null)[] = []

    for (let dayIndex = 0; dayIndex < 7; dayIndex++) {
      const date = new Date(cursor)
      if (date < first || date > last) {
        week.push(null)
      } else {
        const key = toIsoDate(date)
        week.push({ date, key, count: counts.get(key) ?? 0 })
      }
      cursor.setUTCDate(cursor.getUTCDate() + 1)
    }

    // Label a column with its month only when the month changes, so the strip reads as a
    // timeline instead of repeating the same word a dozen times.
    const firstRealDay = week.find((cell): cell is Cell => cell !== null)
    if (firstRealDay && firstRealDay.date.getUTCMonth() !== lastMonth) {
      lastMonth = firstRealDay.date.getUTCMonth()
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

/** 0 for Monday through 6 for Sunday. */
function isoWeekdayIndex(date: Date): number {
  return (date.getUTCDay() + 6) % 7
}
