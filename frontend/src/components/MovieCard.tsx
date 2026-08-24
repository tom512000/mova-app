import { Link } from 'react-router-dom'
import type { MovieSummary } from '@/types/api'
import { Badge } from '@/components/ui/Badge'
import { StarRating } from '@/components/ui/StarRating'

const STATUS_LABEL: Partial<Record<MovieSummary['enrichmentStatus'], string>> = {
  pending: 'En cours',
  ambiguous: 'À vérifier',
  failed: 'Échec TMDB',
  excluded: 'Non trouvé sur TMDB',
}

export function MovieCard({ movie }: { movie: MovieSummary }) {
  const statusLabel = STATUS_LABEL[movie.enrichmentStatus]

  return (
    <Link to={`/movies/${movie.id}`} className="hard-shadow-hover group block border border-ink bg-paper">
      <div className="relative aspect-2/3 w-full overflow-hidden bg-surface-2">
        {movie.posterUrl ? (
          <img
            src={movie.posterUrl}
            alt={movie.title}
            loading="lazy"
            className="h-full w-full object-cover grayscale transition-all duration-300 group-hover:grayscale-0 group-hover:sepia-[.5]"
          />
        ) : (
          <div className="flex h-full w-full items-center justify-center bg-[radial-gradient(currentColor_1px,transparent_1px)] bg-size-[16px_16px] text-ink/10">
            <span className="bg-paper px-2 font-mono text-[10px] uppercase tracking-widest text-subtle">Pas d'affiche</span>
          </div>
        )}
        {statusLabel && (
          <Badge variant="solid" className="absolute left-2 top-2">
            {statusLabel}
          </Badge>
        )}
      </div>
      <div className="border-t border-ink p-3">
        <p className="truncate font-serif text-sm font-bold leading-tight group-hover:text-accent">{movie.title}</p>
        <div className="mt-1.5 flex items-center justify-between font-mono text-[11px] text-subtle">
          <span>{movie.releaseYear ?? '—'}</span>
          <StarRating rating={movie.myAverageRating} />
        </div>
      </div>
    </Link>
  )
}
