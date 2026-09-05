import { Bar, BarChart, CartesianGrid, LabelList, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import type { DecadeStat } from '@/types/api'
import { CHART_COLORS, CHART_FONT_MONO, useIsDarkMode } from '@/charts/palette'

/**
 * How many films per decade, and how well each decade is rated.
 *
 * The rating rides above its bar as a label rather than as a second series on a right-hand
 * axis. Two axes would let the scale decide how dramatic the trend looks — pick a tight
 * domain and a tenth of a star becomes a cliff — and the palette is monochrome by design,
 * so two overlaid ink-coloured series would be hard to tell apart anyway. A number over its
 * own bar cannot be scaled into saying something else, and it puts the count and the score
 * side by side, which is the only way this chart is honest: five films is a fragile average
 * and the bar next to it says so.
 */
export function DecadeChart({ data }: { data: DecadeStat[] }) {
  const isDark = useIsDarkMode()
  const barColor = isDark ? CHART_COLORS.seriesBlueDark : CHART_COLORS.seriesBlueLight
  const gridColor = isDark ? CHART_COLORS.gridlineDark : CHART_COLORS.gridlineLight
  const axisColor = isDark ? CHART_COLORS.axisDark : CHART_COLORS.axisLight
  const surface = isDark ? CHART_COLORS.surfaceDark : CHART_COLORS.surfaceLight
  const textColor = isDark ? CHART_COLORS.textPrimaryDark : CHART_COLORS.textPrimaryLight

  return (
    <ResponsiveContainer width="100%" height={300}>
      {/* Top margin leaves room for the labels; without it the tallest bar's score is clipped. */}
      <BarChart data={data} margin={{ top: 24, right: 8, left: 0, bottom: 0 }}>
        <CartesianGrid vertical={false} stroke={gridColor} strokeDasharray="0" />
        <XAxis
          dataKey="decade"
          tick={{ fill: axisColor, fontSize: 11, fontFamily: CHART_FONT_MONO }}
          axisLine={{ stroke: gridColor }}
          tickLine={false}
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
          labelFormatter={(decade) => `Années ${decade}`}
          formatter={(value, _name, props) => {
            const bucket = props.payload as DecadeStat
            const rating = null !== bucket.averageRating ? ` · note moy. ${bucket.averageRating}` : ''

            return [`${value} film${Number(value) > 1 ? 's' : ''}${rating}`, 'Sortis dans la décennie']
          }}
        />
        <Bar dataKey="movieCount" fill={barColor} maxBarSize={48}>
          <LabelList
            dataKey="averageRating"
            position="top"
            fill={axisColor}
            fontSize={11}
            fontFamily={CHART_FONT_MONO}
            // A decade nobody rated gets no label at all: "—" floating over an empty
            // column reads as a score rather than as an absence.
            formatter={(value: unknown) =>
              'number' === typeof value ? value.toFixed(2).replace(/0$/, '') : ''
            }
          />
        </Bar>
      </BarChart>
    </ResponsiveContainer>
  )
}
