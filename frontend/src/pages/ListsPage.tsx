import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { fetchLists } from '@/services/listsService'
import { ListCard } from '@/components/lists/ListCard'
import { EmptyState } from '@/components/EmptyState'
import { ErrorState } from '@/components/ErrorState'
import { PageMeta } from '@/components/PageMeta'
import { Skeleton } from '@/components/Skeleton'
import { useSession } from '@/hooks/useSession'

export function ListsPage() {
  const { isViewingOtherProfile } = useSession()
  const { data: lists, isLoading, isError, error } = useQuery({ queryKey: ['lists'], queryFn: fetchLists })

  // A work on two lists is counted on both: these add up the lists, not the library.
  const works = lists?.reduce((sum, list) => sum + list.workCount, 0) ?? 0
  const watched = lists?.reduce((sum, list) => sum + list.watchedCount, 0) ?? 0

  return (
    <div className="flex flex-col gap-8">
      <PageMeta title="Listes" />

      <div className="flex flex-col gap-3 border-b-4 border-ink pb-6 sm:flex-row sm:items-end sm:justify-between">
        <div>
          <h1 className="font-serif text-5xl font-black tracking-tighter sm:text-6xl">Listes</h1>
          <p className="mt-2 max-w-xl font-body text-sm text-subtle">
            Tes listes Letterboxd, relues à la lumière de ce que tu as déjà vu.
          </p>
        </div>
        {lists && lists.length > 0 && (
          <p className="font-mono text-[11px] uppercase tracking-widest text-subtle">
            <b className="text-ink">{lists.length}</b> liste{lists.length > 1 ? 's' : ''} &middot; {works} œuvre
            {works > 1 ? 's' : ''} &middot; {works > 0 ? Math.round((watched / works) * 100) : 0} % vues
          </p>
        )}
      </div>

      {isLoading && <SkeletonListGrid />}
      {isError && <ErrorState message={(error as Error).message} />}

      {lists && lists.length === 0 && (
        <EmptyState
          title="Aucune liste pour l'instant"
          description={
            isViewingOtherProfile
              ? "Ce profil n'a pas encore importé de liste."
              : "Les listes arrivent avec l'export Letterboxd : chaque fichier du dossier lists/ devient une liste ici."
          }
          action={
            isViewingOtherProfile ? undefined : (
              <Link to="/import" className="font-mono text-xs uppercase tracking-widest underline decoration-accent decoration-2 underline-offset-4">
                Importer un export
              </Link>
            )
          }
        />
      )}

      {lists && lists.length > 0 && (
        <div className="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3">
          {lists.map((list, index) => (
            <ListCard key={list.id} list={list} number={index + 1} />
          ))}
        </div>
      )}
    </div>
  )
}

/** The real grid's shape, so nothing jumps when the covers arrive. */
function SkeletonListGrid() {
  return (
    <div className="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3">
      {Array.from({ length: 6 }, (_, index) => (
        <div key={index} className="border border-ink bg-paper">
          <Skeleton className="h-52 w-full" />
          <div className="flex flex-col gap-3 border-t border-ink p-5">
            <Skeleton className="h-2.5 w-24" />
            <Skeleton className="h-6 w-4/5" />
            <Skeleton className="h-3 w-full" />
            <Skeleton className="mt-2 h-2 w-full" />
          </div>
        </div>
      ))}
    </div>
  )
}
