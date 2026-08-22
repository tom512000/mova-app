import { Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import type { GenreStat } from '@/types/api'
import { CHART_COLORS, useIsDarkMode } from '@/charts/palette'

export function GenreBarChart({ data }: { data: GenreStat[] }) {
  const isDark = useIsDarkMode()
  const barColor = isDark ? CHART_COLORS.seriesBlueDark : CHART_COLORS.seriesBlueLight
  const gridColor = isDark ? CHART_COLORS.gridlineDark : CHART_COLORS.gridlineLight
  const axisColor = isDark ? CHART_COLORS.axisDark : CHART_COLORS.axisLight
  const surface = isDark ? CHART_COLORS.surfaceDark : CHART_COLORS.surfaceLight
  const textColor = isDark ? CHART_COLORS.textPrimaryDark : CHART_COLORS.textPrimaryLight

  const top = [...data].sort((a, b) => b.watchCount - a.watchCount).slice(0, 10)

  return (
    <ResponsiveContainer width="100%" height={320}>
      <BarChart data={top} layout="vertical" margin={{ top: 8, right: 24, left: 8, bottom: 0 }}>
        <CartesianGrid horizontal={false} stroke={gridColor} />
        <XAxis type="number" tick={{ fill: axisColor, fontSize: 12 }} axisLine={false} tickLine={false} allowDecimals={false} />
        <YAxis
          type="category"
          dataKey="genreName"
          tick={{ fill: axisColor, fontSize: 12 }}
          axisLine={false}
          tickLine={false}
          width={110}
        />
        <Tooltip
          cursor={{ fill: isDark ? 'rgba(255,255,255,0.04)' : 'rgba(0,0,0,0.03)' }}
          contentStyle={{ background: surface, border: `1px solid ${gridColor}`, borderRadius: 8, color: textColor }}
          formatter={(value, _name, props) => {
            const genre = props.payload as GenreStat
            return [
              `${value} visionnage${Number(value) > 1 ? 's' : ''}${genre.averageRating !== null ? ` · note moy. ${genre.averageRating}` : ''}`,
              genre.genreName,
            ]
          }}
        />
        <Bar dataKey="watchCount" fill={barColor} radius={[0, 4, 4, 0]} maxBarSize={20} />
      </BarChart>
    </ResponsiveContainer>
  )
}
