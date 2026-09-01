export function formatMinutesAsDuration(totalMinutes: number): string {
  const hours = Math.floor(totalMinutes / 60)
  const minutes = totalMinutes % 60
  if (hours === 0) return `${minutes} min`
  return `${hours} h ${minutes.toString().padStart(2, '0')}`
}

export function formatMinutesAsDays(totalMinutes: number): string {
  const days = totalMinutes / (60 * 24)
  return `${days.toFixed(1)} j`
}

export function formatRating(rating: number | null): string {
  if (rating === null) return '—'
  return rating.toFixed(2).replace(/\.00$/, '').replace(/0$/, '')
}

export function formatDate(isoDate: string | null): string {
  if (!isoDate) return '—'
  return new Date(isoDate).toLocaleDateString('fr-FR', { year: 'numeric', month: 'short', day: 'numeric' })
}

/**
 * A single day, written out in full: "jeudi 27 août 2026".
 *
 * Read as UTC, which is not a detail: an ISO day parses to midnight UTC, and rendering that
 * in a timezone west of Greenwich lands on the evening before — the label would name the
 * wrong day, and the calendar square it came from would look mislabelled.
 */
export function formatCalendarDay(isoDate: string): string {
  return new Date(`${isoDate}T00:00:00Z`).toLocaleDateString('fr-FR', {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
    year: 'numeric',
    timeZone: 'UTC',
  })
}

export function ratingToStars(rating: number | null): string {
  if (rating === null) return '—'
  const fullStars = Math.floor(rating)
  const hasHalf = rating % 1 !== 0
  return '★'.repeat(fullStars) + (hasHalf ? '½' : '')
}
