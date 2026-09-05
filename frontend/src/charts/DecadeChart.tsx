import type { DecadeStat } from '@/types/api'
import { RatedBarChart } from '@/charts/RatedBarChart'

/** Films per decade of release, and how each decade is rated. */
export function DecadeChart({ data }: { data: DecadeStat[] }) {
  return (
    <RatedBarChart
      data={data.map((decade) => ({
        label: String(decade.decade),
        count: decade.movieCount,
        averageRating: decade.averageRating,
      }))}
      tooltipHeading={(label) => `Années ${label}`}
      countLabel={(count) => `${count} film${count > 1 ? 's' : ''}`}
    />
  )
}
