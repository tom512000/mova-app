import { ArrowDown, ArrowUp, Shuffle, X } from 'lucide-react'
import type { CreditRole, MovieFacets, MovieSortField } from '@/types/api'
import { MEDIA_TYPE_OPTIONS, ROLE_PREFIX, SORT_OPTIONS, type MovieFilterState } from '@/utils/movieSort'
import { formatCalendarDay, ratingToStars } from '@/utils/format'
import { Button } from '@/components/ui/Button'
import { FilterSelect, Option } from '@/components/ui/FilterSelect'

interface MovieFiltersProps {
  state: MovieFilterState
  facets?: MovieFacets
  /** Set while the list is narrowed to one person; the name arrives with the listing. */
  person: { name: string; role: CreditRole | null } | null
  /** Set while the list is narrowed to one studio; the name arrives with the listing. */
  studio: { name: string } | null
  /** Set while the list is narrowed to one day of the activity calendar; '' otherwise. */
  watchedOn: string
  isDirty: boolean
  onChange: (patch: Partial<MovieFilterState>) => void
  onSortChange: (sort: MovieSortField) => void
  onReshuffle: () => void
  onReset: () => void
  onClearPerson: () => void
  onClearStudio: () => void
  onClearWatchedOn: () => void
}

export function MovieFilters({
  state,
  facets,
  person,
  studio,
  watchedOn,
  isDirty,
  onChange,
  onSortChange,
  onReshuffle,
  onReset,
  onClearPerson,
  onClearStudio,
  onClearWatchedOn,
}: MovieFiltersProps) {
  const isRandom = state.sort === 'random'
  const directionLabel = state.direction === 'asc' ? 'Croissant' : 'Décroissant'

  return (
    <div className="flex flex-col gap-4 border border-ink p-4">
      {(person || studio || watchedOn !== '') && (
        <div className="flex flex-wrap items-center gap-3 border-b border-muted pb-4">
          {person && (
            <Chip
              prefix={person.role ? ROLE_PREFIX[person.role] : null}
              label={person.name}
              clearLabel={`Retirer le filtre ${person.name}`}
              onClear={onClearPerson}
            />
          )}
          {studio && (
            <Chip
              prefix="Studio"
              label={studio.name}
              clearLabel={`Retirer le filtre ${studio.name}`}
              onClear={onClearStudio}
            />
          )}
          {watchedOn !== '' && (
            <Chip
              prefix="Vu le"
              label={formatCalendarDay(watchedOn)}
              clearLabel={`Retirer le filtre du ${formatCalendarDay(watchedOn)}`}
              onClear={onClearWatchedOn}
            />
          )}
        </div>
      )}

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
 * A filter picked somewhere other than this bar — a name or a day on the dashboard, neither
 * of which would fit in a dropdown — so it shows up as a removable line above the selects
 * instead. The prefix turns the chip into a sentence: "Réalisé par Quentin Dupieux",
 * "Vu le jeudi 27 août 2026".
 */
function Chip({
  prefix,
  label,
  clearLabel,
  onClear,
}: {
  prefix: string | null
  label: string
  clearLabel: string
  onClear: () => void
}) {
  return (
    <span className="inline-flex items-center gap-2.5 border border-ink bg-ink py-1.5 pl-3 pr-1.5 text-paper">
      {prefix && <span className="font-mono text-[10px] uppercase tracking-widest opacity-60">{prefix}</span>}
      <span className="font-serif text-sm font-bold">{label}</span>
      <button
        type="button"
        onClick={onClear}
        aria-label={clearLabel}
        title="Retirer ce filtre"
        className="p-1 text-paper/70 transition-colors hover:text-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-paper"
      >
        <X className="h-3.5 w-3.5" strokeWidth={2.5} />
      </button>
    </span>
  )
}

