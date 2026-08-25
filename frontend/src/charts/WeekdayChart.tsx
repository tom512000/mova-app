import { Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import type { WeekdayStat } from '@/types/api'
import { CHART_FONT_MONO } from '@/charts/palette'
import { tooltipStyle, useChartTheme } from '@/charts/useChartTheme'

export function WeekdayChart({ data }: { data: WeekdayStat[] }) {
  const theme = useChartTheme()

  return (
    <ResponsiveContainer width="100%" height={240}>
      <BarChart data={data} margin={{ top: 8, right: 8, left: 0, bottom: 0 }}>
        <CartesianGrid vertical={false} stroke={theme.grid} />
        <XAxis
          dataKey="label"
          tick={{ fill: theme.axis, fontSize: 10, fontFamily: CHART_FONT_MONO }}
          axisLine={{ stroke: theme.grid }}
          tickLine={false}
          interval={0}
          tickFormatter={(label: string) => label.slice(0, 3)}
        />
        <YAxis
          tick={{ fill: theme.axis, fontSize: 11, fontFamily: CHART_FONT_MONO }}
          axisLine={false}
          tickLine={false}
          allowDecimals={false}
        />
        <Tooltip
          cursor={{ fill: theme.cursorFill }}
          contentStyle={tooltipStyle(theme.surface, theme.text)}
          formatter={(value, _name, props) => {
            const day = props.payload as WeekdayStat
            return [
              `${value} visionnage${Number(value) > 1 ? 's' : ''}${day.averageRating !== null ? ` · note moy. ${day.averageRating}` : ''}`,
              day.label,
            ]
          }}
        />
        <Bar dataKey="watchCount" fill={theme.series} maxBarSize={40} />
      </BarChart>
    </ResponsiveContainer>
  )
}
