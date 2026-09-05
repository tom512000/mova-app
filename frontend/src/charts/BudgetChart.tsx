import type { BudgetBand } from '@/types/api'
import { RatedBarChart } from '@/charts/RatedBarChart'

/**
 * Films per budget bracket, and how each bracket is rated.
 *
 * Two labels per bracket: a short one on the axis, where four brackets share half a row, and
 * the sentence in the tooltip. Squeezing "Moins de 5 millions" onto a tick would either wrap
 * it into unreadability or make recharts drop it.
 */
export function BudgetChart({ data }: { data: BudgetBand[] }) {
  return (
    <RatedBarChart
      data={data.map((band) => ({
        label: shortLabel(band),
        count: band.movieCount,
        averageRating: band.averageRating,
      }))}
      maxBarSize={64}
      tooltipHeading={(label) => fullLabel(data, label)}
      countLabel={(count) => `${count} film${count > 1 ? 's' : ''}`}
    />
  )
}

/** In millions of dollars, which is the unit TMDB records budgets in. */
function millions(amount: number): string {
  return `${Math.round(amount / 1_000_000)} M$`
}

function shortLabel(band: BudgetBand): string {
  if (band.minBudget === 0) return `< ${millions(band.maxBudget ?? 0)}`
  if (band.maxBudget === null) return `> ${millions(band.minBudget)}`
  return `${Math.round(band.minBudget / 1_000_000)}–${millions(band.maxBudget)}`
}

function fullLabel(bands: BudgetBand[], label: string): string {
  const band = bands.find((candidate) => shortLabel(candidate) === label)
  if (band === undefined) return label
  if (band.minBudget === 0) return `Moins de ${millions(band.maxBudget ?? 0)} de budget`
  if (band.maxBudget === null) return `Plus de ${millions(band.minBudget)} de budget`

  return `De ${millions(band.minBudget)} à ${millions(band.maxBudget)} de budget`
}
