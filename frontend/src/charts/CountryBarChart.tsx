import { Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import type { CountryStat } from '@/types/api'
import { CHART_FONT_MONO } from '@/charts/palette'
import { tooltipStyle, useChartTheme } from '@/charts/useChartTheme'

export function CountryBarChart({ data }: { data: CountryStat[] }) {
  const theme = useChartTheme()

  return (
    <ResponsiveContainer width="100%" height={Math.max(260, data.length * 28)}>
      <BarChart data={data} layout="vertical" margin={{ top: 8, right: 24, left: 8, bottom: 0 }}>
        <CartesianGrid horizontal={false} stroke={theme.grid} />
        <XAxis
          type="number"
          tick={{ fill: theme.axis, fontSize: 11, fontFamily: CHART_FONT_MONO }}
          axisLine={false}
          tickLine={false}
          allowDecimals={false}
        />
        <YAxis
          type="category"
          dataKey="countryName"
          tick={{ fill: theme.axis, fontSize: 11, fontFamily: CHART_FONT_MONO }}
          axisLine={false}
          tickLine={false}
          width={130}
        />
        <Tooltip
          cursor={{ fill: theme.cursorFill }}
          contentStyle={tooltipStyle(theme.surface, theme.text)}
          formatter={(value, _name, props) => {
            const country = props.payload as CountryStat
            return [
              `${value} film${Number(value) > 1 ? 's' : ''}${country.averageRating !== null ? ` · note moy. ${country.averageRating}` : ''}`,
              country.countryName,
            ]
          }}
        />
        <Bar dataKey="movieCount" fill={theme.series} maxBarSize={20} />
      </BarChart>
    </ResponsiveContainer>
  )
}
