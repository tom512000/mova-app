import { Link } from 'react-router-dom'
import type { MovieSummary } from '@/types/api'
import { formatRating } from '@/utils/format'

export function MovieCard({ movie }: { movie: MovieSummary }) {
  return (
    <Link
      to={`/movies/${movie.id}`}
      className="group overflow-hidden rounded-xl border border-neutral-200 bg-white transition-shadow hover:shadow-lg dark:border-neutral-800 dark:bg-neutral-900"
    >
      <div className="aspect-[2/3] w-full bg-neutral-200 dark:bg-neutral-800">
        {movie.posterUrl ? (
          <img src={movie.posterUrl} alt={movie.title} className="h-full w-full object-cover" loading="lazy" />
        ) : (
          <div className="flex h-full w-full items-center justify-center text-xs text-neutral-400">Pas d'affiche</div>
        )}
      </div>
      <div className="p-3">
        <p className="truncate text-sm font-medium group-hover:underline">{movie.title}</p>
        <div className="mt-1 flex items-center justify-between text-xs text-neutral-500 dark:text-neutral-400">
          <span>{movie.releaseYear ?? '—'}</span>
          <span>★ {formatRating(movie.myAverageRating)}</span>
        </div>
        {movie.enrichmentStatus !== 'enriched' && (
          <span className="mt-1 inline-block rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-medium text-amber-700 dark:bg-amber-900/40 dark:text-amber-400">
            {movie.enrichmentStatus === 'pending' && 'En cours d’enrichissement'}
            {movie.enrichmentStatus === 'ambiguous' && 'À vérifier'}
            {movie.enrichmentStatus === 'failed' && 'Échec TMDB'}
          </span>
        )}
      </div>
    </Link>
  )
}
