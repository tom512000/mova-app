import { useQuery } from '@tanstack/react-query'
import { useEffect, useId, useRef, useState } from 'react'
import { Search } from 'lucide-react'
import { fetchMovies } from '@/services/moviesService'
import { useDebouncedValue } from '@/hooks/useDebouncedValue'
import { SkeletonSuggestions } from '@/components/Skeleton'
import type { MovieSummary } from '@/types/api'

const MAX_SUGGESTIONS = 8
/** Below this a search returns most of the library, which is not a suggestion list. */
const MIN_QUERY_LENGTH = 2

/**
 * Type a title, pick it from the list. Built on the listing endpoint, so it only ever
 * suggests films the profile has actually watched.
 */
export function MovieSearchCombobox({
  onSelect,
  excludeIds = [],
  disabled = false,
  placeholder = 'Cherche un film que tu as vu…',
}: {
  onSelect: (movie: MovieSummary) => void
  /** Films already played, dropped from the list rather than offered and refused. */
  excludeIds?: string[]
  disabled?: boolean
  placeholder?: string
}) {
  const [query, setQuery] = useState('')
  const [highlight, setHighlight] = useState(0)
  const debounced = useDebouncedValue(query, 250)
  const listId = useId()
  const containerRef = useRef<HTMLDivElement>(null)

  const { data, isFetching } = useQuery({
    queryKey: ['movies', 'suggest', debounced],
    queryFn: () => fetchMovies({ q: debounced, perPage: MAX_SUGGESTIONS + excludeIds.length }),
    enabled: debounced.trim().length >= MIN_QUERY_LENGTH && !disabled,
  })

  const suggestions = (data?.items ?? [])
    .filter((movie) => !excludeIds.includes(movie.id))
    .slice(0, MAX_SUGGESTIONS)

  // The list can shrink under the cursor between two keystrokes' worth of results.
  const active = Math.min(highlight, Math.max(0, suggestions.length - 1))

  const typed = query.trim()
  const isSearchable = typed.length >= MIN_QUERY_LENGTH && !disabled

  /*
   * "Searching" has to cover the debounce as well as the request. For 250ms after a
   * keystroke the query has not been sent yet — isFetching is false and `data` still holds
   * the previous letters' results — so asking React Query alone would call that state
   * "settled" and flash the wrong answer under the cursor. Comparing the debounced value to
   * what is actually typed closes that window.
   */
  const isSearching = isSearchable && (isFetching || debounced.trim() !== typed)

  useEffect(() => {
    function onPointerDown(event: MouseEvent) {
      if (!containerRef.current?.contains(event.target as Node)) setQuery('')
    }
    document.addEventListener('mousedown', onPointerDown)
    return () => document.removeEventListener('mousedown', onPointerDown)
  }, [])

  function choose(movie: MovieSummary) {
    setQuery('')
    onSelect(movie)
  }

  function onKeyDown(event: React.KeyboardEvent<HTMLInputElement>) {
    if (event.key === 'Escape') {
      setQuery('')
      return
    }
    // `suggestions` still holds the previous keystrokes' results while the next request
    // is in flight, and those rows are no longer on screen — without this, Enter picks a
    // film the list is not showing.
    if (isSearching || suggestions.length === 0) return

    if (event.key === 'ArrowDown') {
      event.preventDefault()
      setHighlight((index) => (index + 1) % suggestions.length)
    } else if (event.key === 'ArrowUp') {
      event.preventDefault()
      setHighlight((index) => (index - 1 + suggestions.length) % suggestions.length)
    } else if (event.key === 'Enter') {
      event.preventDefault()
      choose(suggestions[active])
    }
  }

  /*
   * The panel now opens for the whole of a search rather than only once it has succeeded.
   * Saying nothing while the request was in flight and then appearing was one jump; opening
   * on the spinner and snapping shut on zero results would have been two, which is why the
   * empty case gets a line of its own rather than closing the panel.
   */
  const isOpen = isSearchable && (isSearching || suggestions.length > 0 || undefined !== data)

  return (
    <div ref={containerRef} className="relative">
      <div className="flex items-center gap-2 border-b-2 border-ink">
        <Search className="h-4 w-4 shrink-0 text-subtle" strokeWidth={2} aria-hidden />
        <input
          value={query}
          onChange={(event) => {
            setQuery(event.target.value)
            // Typing rebuilds the list, so the previous position means nothing.
            setHighlight(0)
          }}
          onKeyDown={onKeyDown}
          disabled={disabled}
          placeholder={placeholder}
          role="combobox"
          aria-expanded={isOpen}
          aria-controls={listId}
          aria-autocomplete="list"
          aria-activedescendant={suggestions.length > 0 ? `${listId}-${active}` : undefined}
          className="w-full bg-transparent py-2.5 font-mono text-sm focus-visible:outline-none disabled:opacity-40"
        />
      </div>

      {isOpen && (
        <ul
          id={listId}
          role="listbox"
          className="absolute z-30 mt-1 max-h-80 w-full overflow-y-auto border border-ink bg-paper"
        >
          {/* Presentational rows: neither is an option, and offering them to a screen
              reader as one would put two unselectable entries in the listbox. */}
          {isSearching && (
            <li role="presentation">
              <SkeletonSuggestions />
              <span className="sr-only" role="status">
                Recherche en cours
              </span>
            </li>
          )}

          {!isSearching && suggestions.length === 0 && (
            <li role="presentation">
              <p className="px-3 py-4 text-center font-mono text-xs text-subtle" role="status">
                Aucun film ne correspond
              </p>
            </li>
          )}

          {!isSearching &&
            suggestions.map((movie, index) => (
              <li key={movie.id} id={`${listId}-${index}`} role="option" aria-selected={index === active}>
                <button
                  type="button"
                  // mousedown fires before the outside-click handler clears the query, which
                  // would otherwise unmount this button before its click ever lands.
                  onMouseDown={(event) => event.preventDefault()}
                  onClick={() => choose(movie)}
                  onMouseEnter={() => setHighlight(index)}
                  className={`flex w-full items-center gap-3 px-3 py-2 text-left transition-colors ${
                    index === active ? 'bg-ink text-paper' : 'hover:bg-surface'
                  }`}
                >
                  {movie.posterUrl ? (
                    <img src={movie.posterUrl} alt="" className="h-12 w-8 shrink-0 object-cover grayscale" />
                  ) : (
                    <span className="h-12 w-8 shrink-0 bg-surface-2" aria-hidden />
                  )}
                  <span className="min-w-0 flex-1 truncate font-serif text-sm font-bold">{movie.title}</span>
                  <span className="shrink-0 font-mono text-xs opacity-70">{movie.releaseYear ?? '—'}</span>
                </button>
              </li>
            ))}
        </ul>
      )}
    </div>
  )
}
