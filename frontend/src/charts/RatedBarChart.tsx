import { Bar, BarChart, CartesianGrid, LabelList, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { CHART_COLORS, CHART_FONT_MONO, useIsDarkMode } from '@/charts/palette'

export interface RatedBar {
  /** The x-axis tick, and the row key. Unique within one chart. */
  label: string
  count: number
  averageRating: number | null
}

/**
 * How many works fall in each bucket, and how well each bucket is rated.
 *
 * The rating rides above its bar as a label rather than as a second series on a right-hand
 * axis. Two axes would let the scale decide how dramatic the trend looks — pick a tight
 * domain and a tenth of a star becomes a cliff — and the palette is monochrome by design, so
 * two overlaid ink-coloured series would be hard to tell apart anyway. A number over its own
 * bar cannot be scaled into saying something else, and it puts the count and the score side
 * by side, which is the only way this shape is honest: a bucket holding five works has a
 * fragile average, and the bar next to the number says so.
 *
 * Shared by the decades and the budget brackets. They ask the same question of two different
 * groupings, and the whole point of showing them side by side is that a reader can compare
 * them — which only works if they are drawn the same way.
 */
export function RatedBarChart({
  data,
  height = 300,
  maxBarSize = 48,
  tooltipHeading,
  countLabel,
}: {
  data: RatedBar[]
  height?: number
  maxBarSize?: number
  /** The tooltip's title for a bucket, e.g. "Années 1990". */
  tooltipHeading: (label: string) => string
  /** What the bar counts, spelled out for the tooltip: "12 films". */
  countLabel: (count: number) => string
}) {
  const isDark = useIsDarkMode()
  const barColor = isDark ? CHART_COLORS.seriesBlueDark : CHART_COLORS.seriesBlueLight
  const gridColor = isDark ? CHART_COLORS.gridlineDark : CHART_COLORS.gridlineLight
  const axisColor = isDark ? CHART_COLORS.axisDark : CHART_COLORS.axisLight
  const surface = isDark ? CHART_COLORS.surfaceDark : CHART_COLORS.surfaceLight
  const textColor = isDark ? CHART_COLORS.textPrimaryDark : CHART_COLORS.textPrimaryLight

  return (
    <ResponsiveContainer width="100%" height={height}>
      {/* Top margin leaves room for the labels; without it the tallest bar's score is clipped. */}
      <BarChart data={data} margin={{ top: 24, right: 8, left: 0, bottom: 0 }}>
        <CartesianGrid vertical={false} stroke={gridColor} strokeDasharray="0" />
        <XAxis
          dataKey="label"
          tick={{ fill: axisColor, fontSize: 11, fontFamily: CHART_FONT_MONO }}
          axisLine={{ stroke: gridColor }}
          tickLine={false}
          // Every tick, always. Recharts drops labels it thinks are crowded, and a bucket
          // whose name has vanished is worse than a chart that has to be read slowly.
          interval={0}
        />
        <YAxis
          tick={{ fill: axisColor, fontSize: 11, fontFamily: CHART_FONT_MONO }}
          axisLine={false}
          tickLine={false}
          allowDecimals={false}
        />
        <Tooltip
          cursor={{ fill: isDark ? 'rgba(255,255,255,0.05)' : 'rgba(0,0,0,0.04)' }}
          contentStyle={{ background: surface, border: `1px solid ${textColor}`, color: textColor, fontFamily: CHART_FONT_MONO, fontSize: 12 }}
          labelStyle={{ color: textColor, fontWeight: 600 }}
          labelFormatter={(label) => tooltipHeading(String(label))}
          formatter={(value, _name, props) => {
            const bar = props.payload as RatedBar
            const rating = null !== bar.averageRating ? ` · note moy. ${bar.averageRating}` : ''

            return [`${countLabel(Number(value))}${rating}`, 'Dans cette tranche']
          }}
        />
        <Bar dataKey="count" fill={barColor} maxBarSize={maxBarSize}>
          <LabelList
            dataKey="averageRating"
            position="top"
            fill={axisColor}
            fontSize={11}
            fontFamily={CHART_FONT_MONO}
            // A bucket nobody rated gets no label at all: "—" floating over an empty column
            // reads as a score rather than as an absence.
            formatter={(value: unknown) =>
              'number' === typeof value ? value.toFixed(2).replace(/0$/, '') : ''
            }
          />
        </Bar>
      </BarChart>
    </ResponsiveContainer>
  )
}
