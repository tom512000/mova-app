import { ArrowDown, ArrowUp, ChevronDown, Shuffle, X } from 'lucide-react'
import type { ReactNode } from 'react'
import type { CreditRole, MovieFacets, MovieSortField } from '@/types/api'
import { MEDIA_TYPE_OPTIONS, ROLE_PREFIX, SORT_OPTIONS, type MovieFilterState } from '@/utils/movieSort'
import { ratingToStars } from '@/utils/format'
import { Button } from '@/components/ui/Button'

interface MovieFiltersProps {
  state: MovieFilterState
  facets?: MovieFacets
  /** Set while the list is narrowed to one person; the name arrives with the listing. */
  person: { name: string; role: CreditRole | null } | null
  isDirty: boolean
  onChange: (patch: Partial<MovieFilterState>) => void
  onSortChange: (sort: MovieSortField) => void
  onReshuffle: () => void
  onReset: () => void
  onClearPerson: () => void
}

export function MovieFilters({
  state,
  facets,
  person,
  isDirty,
  onChange,
  onSortChange,
  onReshuffle,
  onReset,
  onClearPerson,
}: MovieFiltersProps) {
  const isRandom = state.sort === 'random'
  const directionLabel = state.direction === 'asc' ? 'Croissant' : 'Décroissant'

  return (
    <div className="flex flex-col gap-4 border border-ink p-4">
      {person && <PersonChip person={person} onClear={onClearPerson} />}

      <div className="flex flex-col gap-4 sm:flex-row sm:flex-wrap sm:items-end sm:gap-x-6">
        <FilterSelect
          label="Type"
          value={state.mediaType}
          onChange={(mediaType) => onChange({ mediaType })}
          className="sm:w-32"
        >
          {MEDIA_TYPE_OPTIONS.map((option) => (
            <Option key={option.value} value={option.value}>
              {option.label}
            </Option>
          ))}
        </FilterSelect>

        <FilterSelect
          label="Genre"
          value={state.genre}
          onChange={(genre) => onChange({ genre })}
          className="sm:w-44"
        >
          <Option value="">Tous</Option>
          {facets?.genres.map((genre) => (
            <Option key={genre} value={genre}>
              {genre}
            </Option>
          ))}
        </FilterSelect>

        <FilterSelect
          label="Note"
          value={state.rating}
          onChange={(rating) => onChange({ rating })}
          className="sm:w-36"
        >
          <Option value="">Toutes</Option>
          {facets?.ratings.map((rating) => (
            <Option key={rating} value={String(rating)}>
              {ratingToStars(rating)}
            </Option>
          ))}
          {facets?.hasUnrated && <Option value="none">Non noté</Option>}
        </FilterSelect>

        <FilterSelect
          label="Année"
          value={state.year}
          onChange={(year) => onChange({ year })}
          className="sm:w-28"
        >
          <Option value="">Toutes</Option>
          {facets?.years.map((year) => (
            <Option key={year} value={String(year)}>
              {year}
            </Option>
          ))}
        </FilterSelect>

        {/* The sort group sits behind a rule: everything left of it narrows the list,
            everything right of it only reorders what is left. */}
        <div className="flex items-end gap-3 sm:ml-auto sm:border-l sm:border-muted sm:pl-6">
          <FilterSelect
            label="Trier par"
            value={state.sort}
            onChange={(sort) => onSortChange(sort as MovieSortField)}
            className="w-full sm:w-44"
          >
            {SORT_OPTIONS.map((option) => (
              <Option key={option.value} value={option.value}>
                {option.label}
              </Option>
            ))}
          </FilterSelect>

          {isRandom ? (
            <Button variant="secondary" size="icon" onClick={onReshuffle} title="Remélanger" aria-label="Remélanger">
              <Shuffle className="h-4 w-4" strokeWidth={2} />
            </Button>
          ) : (
            <Button
              variant="secondary"
              size="icon"
              onClick={() => onChange({ direction: state.direction === 'asc' ? 'desc' : 'asc' })}
              title={directionLabel}
              aria-label={`Ordre : ${directionLabel}`}
            >
              {state.direction === 'asc' ? (
                <ArrowUp className="h-4 w-4" strokeWidth={2} />
              ) : (
                <ArrowDown className="h-4 w-4" strokeWidth={2} />
              )}
            </Button>
          )}

          {isDirty && (
            <Button variant="ghost" size="icon" onClick={onReset} title="Réinitialiser" aria-label="Réinitialiser les filtres">
              <X className="h-4 w-4" strokeWidth={2} />
            </Button>
          )}
        </div>
      </div>
    </div>
  )
}

/**
 * A person is picked from the dashboard, never from a dropdown — a few hundred names would
 * not fit one — so it shows up as a removable line above the selects instead.
 */
function PersonChip({
  person,
  onClear,
}: {
  person: { name: string; role: CreditRole | null }
  onClear: () => void
}) {
  return (
    <div className="flex items-center gap-3 border-b border-muted pb-4">
      <span className="inline-flex items-center gap-2.5 border border-ink bg-ink py-1.5 pl-3 pr-1.5 text-paper">
        {person.role && (
          <span className="font-mono text-[10px] uppercase tracking-widest opacity-60">
            {ROLE_PREFIX[person.role]}
          </span>
        )}
        <span className="font-serif text-sm font-bold">{person.name}</span>
        <button
          type="button"
          onClick={onClear}
          aria-label={`Retirer le filtre ${person.name}`}
          title="Retirer ce filtre"
          className="p-1 text-paper/70 transition-colors hover:text-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-paper"
        >
          <X className="h-3.5 w-3.5" strokeWidth={2.5} />
        </button>
      </span>
    </div>
  )
}

function FilterSelect({
  label,
  value,
  onChange,
  className,
  children,
}: {
  label: string
  value: string
  onChange: (value: string) => void
  className?: string
  children: ReactNode
}) {
  return (
    <label className={`group flex flex-col gap-1 ${className ?? ''}`}>
      <span className="font-mono text-[10px] uppercase tracking-widest text-subtle">{label}</span>
      <span className="relative flex items-center">
        <select
          value={value}
          onChange={(event) => onChange(event.target.value)}
          // appearance-none drops the platform arrow so the lucide chevron can sit on the
          // rule; pr-6 reserves its room.
          className="w-full cursor-pointer appearance-none truncate border-0 border-b-2 border-ink bg-transparent py-1 pl-0 pr-6 font-sans text-sm text-ink focus:outline-none focus-visible:ring-2 focus-visible:ring-accent"
        >
          {children}
        </select>
        <ChevronDown
          aria-hidden
          className="pointer-events-none absolute right-1 h-3.5 w-3.5 text-subtle transition-colors group-hover:text-accent"
          strokeWidth={2}
        />
      </span>
    </label>
  )
}

/** Native options inherit the OS palette, so the theme colours have to be restated here. */
function Option({ value, children }: { value: string; children: ReactNode }) {
  return (
    <option value={value} className="bg-paper text-ink">
      {children}
    </option>
  )
}
