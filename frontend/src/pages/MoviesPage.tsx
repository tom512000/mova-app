import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { fetchMovies } from '@/services/moviesService'
import { MovieCard } from '@/components/MovieCard'
import { SkeletonGrid } from '@/components/Skeleton'
import { ErrorState } from '@/components/ErrorState'
import { EmptyState } from '@/components/EmptyState'
import { Button } from '@/components/ui/Button'

export function MoviesPage() {
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)

  const { data, isLoading, isError, error } = useQuery({
    queryKey: ['movies', search, page],
    queryFn: () => fetchMovies({ q: search || undefined, page, perPage: 24 }),
  })

  const totalPages = data ? Math.max(1, Math.ceil(data.total / data.perPage)) : 1

  return (
    <div className="flex flex-col gap-8">
      <div className="flex flex-col gap-4 border-b-4 border-ink pb-6 sm:flex-row sm:items-end sm:justify-between">
        <h1 className="font-serif text-5xl font-black tracking-tighter sm:text-6xl">Films</h1>
        <input
          value={search}
          onChange={(e) => {
            setSearch(e.target.value)
            setPage(1)
          }}
          placeholder="Rechercher un titre..."
          className="w-full border-b-2 border-ink bg-transparent px-1 py-2 font-mono text-sm focus-visible:bg-surface focus-visible:outline-none sm:w-72"
        />
      </div>

      {isLoading && <SkeletonGrid count={12} />}
      {isError && <ErrorState message={(error as Error).message} />}

      {data && data.items.length === 0 && (
        <EmptyState title="Aucun film trouvé" description="Essaie une autre recherche, ou importe tes données Letterboxd." />
      )}

      {data && data.items.length > 0 && (
        <>
          <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6">
            {data.items.map((movie) => (
              <MovieCard key={movie.id} movie={movie} />
            ))}
          </div>
          <div className="flex items-center justify-center gap-4">
            <Button variant="secondary" size="sm" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>
              Précédent
            </Button>
            <span className="font-mono text-xs uppercase tracking-widest text-subtle">
              Page {page} / {totalPages}
            </span>
            <Button variant="secondary" size="sm" disabled={page >= totalPages} onClick={() => setPage((p) => p + 1)}>
              Suivant
            </Button>
          </div>
        </>
      )}
    </div>
  )
}
