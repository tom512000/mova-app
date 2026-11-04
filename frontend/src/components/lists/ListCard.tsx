import { Link } from 'react-router-dom'
import type { MovieListSummary } from '@/types/api'
import { Badge } from '@/components/ui/Badge'
import { ListCover } from '@/components/lists/ListCover'
import { ListProgress } from '@/components/lists/ListProgress'
import { formatDate } from '@/utils/format'

export function ListCard({ list, number }: { list: MovieListSummary; number: number }) {
  return (
    <Link to={`/lists/${list.id}`} className="hard-shadow-hover group flex flex-col border border-ink bg-paper">
      <ListCover posterUrls={list.posterUrls} name={list.name} />

      <div className="flex flex-1 flex-col gap-3 p-5">
        {/* A running number, as a paper numbers its columns: it gives a page of covers a
            reading order without pretending the lists are ranked. */}
        <p className="font-mono text-[10px] uppercase tracking-widest text-subtle">
          N° {String(number).padStart(2, '0')}
          {list.createdOn && <> &middot; créée le {formatDate(list.createdOn)}</>}
        </p>

        <h2 className="line-clamp-2 text-balance font-serif text-2xl font-black leading-tight tracking-tight group-hover:text-accent">
          {list.name}
        </h2>

        {list.description && <p className="line-clamp-2 font-body text-sm text-subtle">{list.description}</p>}

        {list.tags.length > 0 && (
          <div className="flex flex-wrap gap-1.5">
            {list.tags.map((tag) => (
              <Badge key={tag}>{tag}</Badge>
            ))}
          </div>
        )}

        <ListProgress watched={list.watchedCount} total={list.workCount} className="mt-auto pt-2" />
      </div>
    </Link>
  )
}
