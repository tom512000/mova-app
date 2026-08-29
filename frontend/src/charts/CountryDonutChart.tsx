import { useMemo } from 'react'
import { Cell, Pie, PieChart, ResponsiveContainer, Tooltip } from 'recharts'
import type { CountryStat } from '@/types/api'
import { tooltipStyle, useChartTheme } from '@/charts/useChartTheme'

/** Slices drawn individually; everything past this is gathered into "Autres". */
const NAMED_SLICES = 9

/**
 * The lightest a slice is allowed to get. Below this the tail stops reading as ink on
 * paper and starts looking like an empty wedge.
 */
const FAINTEST = 0.22

interface Slice {
  name: string
  movieCount: number
  /** Null on the grouped tail, which averages nothing meaningful. */
  averageRating: number | null
  /** How many countries this slice stands for — 1, except on "Autres". */
  countries: number
}

/**
 * Country shares as a ring rather than the bars every other panel uses.
 *
 * Two things about this data shape decide the design. A co-production counts once for
 * each country involved, so the categories overlap and the parts are parts of the
 * *production credits*, never of the films — 721 films carry 961 credits here. And the
 * tail is long and thin: the top two countries take three quarters of it, while twenty-odd
 * others sit under one percent each. Slicing all of them would draw a fan of invisible
 * hairlines, so everything past the ninth is gathered into one honest "Autres" wedge and
 * the ring still closes on the whole.
 *
 * Country names are never written on the slices. That is what made the bar chart's axis
 * chop "United States of America" in half, and a pie is worse at it — labels collide as
 * soon as two slices are adjacent and thin. They go in the list beside it instead, where
 * they have a full line each.
 */
export function CountryDonutChart({ data }: { data: CountryStat[] }) {
  const theme = useChartTheme()

  const slices = useMemo<Slice[]>(() => {
    const ranked = [...data].sort((a, b) => b.movieCount - a.movieCount)
    const named = ranked.slice(0, NAMED_SLICES).map((country) => ({
      name: country.countryName,
      movieCount: country.movieCount,
      averageRating: country.averageRating,
      countries: 1,
    }))

    const tail = ranked.slice(NAMED_SLICES)
    if (tail.length === 0) return named

    return [
      ...named,
      {
        name: 'Autres',
        movieCount: tail.reduce((sum, country) => sum + country.movieCount, 0),
        averageRating: null,
        countries: tail.length,
      },
    ]
  }, [data])

  const totalCredits = slices.reduce((sum, slice) => sum + slice.movieCount, 0)
  const countryCount = slices.reduce((sum, slice) => sum + slice.countries, 0)

  // A ramp rather than a palette: the house style keeps data in ink, and the accent red is
  // reserved for interaction. Opacity carries the ranking, so the ring reads darkest-first
  // clockwise from the top.
  const shadeOf = (index: number) =>
    slices.length <= 1 ? 1 : 1 - (index / (slices.length - 1)) * (1 - FAINTEST)

  if (totalCredits === 0) return null

  return (
    <div className="flex flex-col items-center gap-6 sm:flex-row sm:items-start">
      <div className="relative shrink-0" style={{ width: 208, height: 208 }}>
        <ResponsiveContainer width="100%" height="100%">
          <PieChart>
            <Pie
              data={slices}
              dataKey="movieCount"
              nameKey="name"
              cx="50%"
              cy="50%"
              innerRadius={58}
              outerRadius={100}
              // Clockwise from twelve o'clock, so the biggest share starts where the eye does.
              startAngle={90}
              endAngle={-270}
              paddingAngle={0}
              // Paper-coloured, so neighbouring shades stay separate even when their tones
              // are close — the engraved look the rest of the app already uses.
              stroke={theme.surface}
              strokeWidth={2}
              isAnimationActive={false}
            >
              {slices.map((slice, index) => (
                <Cell key={slice.name} fill={theme.series} fillOpacity={shadeOf(index)} />
              ))}
            </Pie>
            <Tooltip
              contentStyle={tooltipStyle(theme.surface, theme.text)}
              formatter={(value, _name, props) => {
                const slice = props.payload as Slice
                const films = `${value} film${Number(value) > 1 ? 's' : ''}`
                const note = slice.averageRating !== null ? ` · note moy. ${slice.averageRating}` : ''
                const grouped = slice.countries > 1 ? ` · ${slice.countries} pays` : ''

                return [`${films}${note}${grouped}`, slice.name]
              }}
            />
          </PieChart>
        </ResponsiveContainer>

        {/* Over the hole rather than inside the SVG: plain markup takes the app's fonts
            and theme tokens without restating them as chart properties. */}
        <div className="pointer-events-none absolute inset-0 flex flex-col items-center justify-center">
          <span className="font-mono text-3xl font-semibold leading-none tabular-nums">{countryCount}</span>
          <span className="mt-1 font-mono text-[10px] uppercase tracking-widest text-subtle">pays</span>
        </div>
      </div>

      <ul className="w-full min-w-0 flex-1 divide-y divide-ink/10">
        {slices.map((slice, index) => (
          <li key={slice.name} className="flex items-baseline gap-3 py-1.5 first:pt-0 last:pb-0">
            <span
              aria-hidden
              className="mt-1 h-2.5 w-2.5 shrink-0 border border-ink"
              style={{ background: theme.series, opacity: shadeOf(index) }}
            />
            {/* Wraps onto a second line rather than being cut: the whole point of moving
                the names off the chart. */}
            <span className="min-w-0 flex-1 font-body text-sm leading-snug">
              {slice.name}
              {slice.countries > 1 && (
                <span className="ml-1.5 font-mono text-[10px] uppercase tracking-widest text-faint">
                  {slice.countries} pays
                </span>
              )}
            </span>
            <span className="shrink-0 font-mono text-xs tabular-nums text-subtle">
              {Math.round((slice.movieCount / totalCredits) * 100)}%
            </span>
            <span className="w-10 shrink-0 text-right font-mono text-sm font-semibold tabular-nums">
              {slice.movieCount}
            </span>
          </li>
        ))}
      </ul>
    </div>
  )
}
