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

export function ratingToStars(rating: number | null): string {
  if (rating === null) return '—'
  const fullStars = Math.floor(rating)
  const hasHalf = rating % 1 !== 0
  return '★'.repeat(fullStars) + (hasHalf ? '½' : '')
}
