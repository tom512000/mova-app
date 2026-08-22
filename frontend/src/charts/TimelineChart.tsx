import { Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import type { TimelineBucket } from '@/types/api'
import { CHART_COLORS, useIsDarkMode } from '@/charts/palette'
import { formatMinutesAsDuration } from '@/utils/format'

export function TimelineChart({ data }: { data: TimelineBucket[] }) {
  const isDark = useIsDarkMode()
  const barColor = isDark ? CHART_COLORS.seriesBlueDark : CHART_COLORS.seriesBlueLight
  const gridColor = isDark ? CHART_COLORS.gridlineDark : CHART_COLORS.gridlineLight
  const axisColor = isDark ? CHART_COLORS.axisDark : CHART_COLORS.axisLight
  const surface = isDark ? CHART_COLORS.surfaceDark : CHART_COLORS.surfaceLight
  const textColor = isDark ? CHART_COLORS.textPrimaryDark : CHART_COLORS.textPrimaryLight

  return (
    <ResponsiveContainer width="100%" height={280}>
      <BarChart data={data} margin={{ top: 8, right: 8, left: 0, bottom: 0 }}>
        <CartesianGrid vertical={false} stroke={gridColor} strokeDasharray="0" />
        <XAxis dataKey="period" tick={{ fill: axisColor, fontSize: 12 }} axisLine={{ stroke: gridColor }} tickLine={false} />
        <YAxis tick={{ fill: axisColor, fontSize: 12 }} axisLine={false} tickLine={false} allowDecimals={false} />
        <Tooltip
          cursor={{ fill: isDark ? 'rgba(255,255,255,0.04)' : 'rgba(0,0,0,0.03)' }}
          contentStyle={{ background: surface, border: `1px solid ${gridColor}`, borderRadius: 8, color: textColor }}
          labelStyle={{ color: textColor, fontWeight: 600 }}
          formatter={(value, name, props) => {
            if (name === 'watchCount') {
              const bucket = props.payload as TimelineBucket
              return [
                `${value} film${Number(value) > 1 ? 's' : ''} · ${formatMinutesAsDuration(bucket.watchTimeMinutes)}${
                  bucket.averageRating !== null ? ` · note moy. ${bucket.averageRating}` : ''
                }`,
                'Visionnages',
              ]
            }
            return [String(value), String(name)]
          }}
        />
        <Bar dataKey="watchCount" fill={barColor} radius={[4, 4, 0, 0]} maxBarSize={36} />
      </BarChart>
    </ResponsiveContainer>
  )
}
