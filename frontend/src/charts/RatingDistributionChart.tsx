import { Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import type { RatingDistributionPoint } from '@/types/api'
import { CHART_COLORS, useIsDarkMode } from '@/charts/palette'

export function RatingDistributionChart({ data }: { data: RatingDistributionPoint[] }) {
  const isDark = useIsDarkMode()
  const barColor = isDark ? CHART_COLORS.seriesBlueDark : CHART_COLORS.seriesBlueLight
  const gridColor = isDark ? CHART_COLORS.gridlineDark : CHART_COLORS.gridlineLight
  const axisColor = isDark ? CHART_COLORS.axisDark : CHART_COLORS.axisLight
  const surface = isDark ? CHART_COLORS.surfaceDark : CHART_COLORS.surfaceLight
  const textColor = isDark ? CHART_COLORS.textPrimaryDark : CHART_COLORS.textPrimaryLight

  return (
    <ResponsiveContainer width="100%" height={260}>
      <BarChart data={data} margin={{ top: 8, right: 8, left: 0, bottom: 0 }}>
        <CartesianGrid vertical={false} stroke={gridColor} />
        <XAxis
          dataKey="rating"
          tick={{ fill: axisColor, fontSize: 12 }}
          axisLine={{ stroke: gridColor }}
          tickLine={false}
          tickFormatter={(v: number) => v.toString()}
        />
        <YAxis tick={{ fill: axisColor, fontSize: 12 }} axisLine={false} tickLine={false} allowDecimals={false} />
        <Tooltip
          cursor={{ fill: isDark ? 'rgba(255,255,255,0.04)' : 'rgba(0,0,0,0.03)' }}
          contentStyle={{ background: surface, border: `1px solid ${gridColor}`, borderRadius: 8, color: textColor }}
          labelFormatter={(v) => `${v} ★`}
          formatter={(value) => [`${value} film${Number(value) > 1 ? 's' : ''}`, 'Note']}
        />
        <Bar dataKey="count" fill={barColor} radius={[4, 4, 0, 0]} maxBarSize={36} />
      </BarChart>
    </ResponsiveContainer>
  )
}
