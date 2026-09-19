import { ArrowDown, ArrowUp, Shuffle, X } from 'lucide-react'
import type { PersonSortField } from '@/types/api'
import { MEDIA_TYPE_OPTIONS } from '@/utils/movieSort'
import { PERSON_SORT_OPTIONS, type PersonFilterState } from '@/utils/personSort'
import { CREDIT_ROLES, ROLE_LABEL } from '@/utils/roles'
import { Button } from '@/components/ui/Button'
import { FilterSelect, Option } from '@/components/ui/FilterSelect'

interface PeopleFiltersProps {
  state: PersonFilterState
  isDirty: boolean
  onChange: (patch: Partial<PersonFilterState>) => void
  onSortChange: (sort: PersonSortField) => void
  onReshuffle: () => void
  onReset: () => void
}

/**
 * The library's filter bar, minus everything that belongs to a film and not to a person.
 *
 * Kept as its own component rather than folded into MovieFilters with half its props made
 * optional: the two share a shape and almost no controls, and one component answering to
 * both would be a list of conditionals before the first real difference.
 */
export function PeopleFilters({
  state,
  isDirty,
  onChange,
  onSortChange,
  onReshuffle,
  onReset,
}: PeopleFiltersProps) {
  const isRandom = state.sort === 'random'
  const directionLabel = state.direction === 'asc' ? 'Croissant' : 'Décroissant'

  return (
    <div className="flex flex-col gap-4 border border-ink p-4">
      <div className="grid grid-cols-2 gap-4 sm:flex sm:flex-row sm:flex-wrap sm:items-end sm:gap-x-6">
        {/* The five jobs, always, and not the ones this library happens to hold. The other
            filters are drawn from facets because a genre absent from a library is a fact
            about that library; a job absent from it is usually a fact about the enrichment
            — producers arrived after most of these films were enriched, series creators
            only exist once there is a series. A menu that quietly loses an entry as the
            data changes reads as a missing feature. */}
        <FilterSelect label="Métier" value={state.role} onChange={(role) => onChange({ role })} className="sm:w-44">
          <Option value="">Tous</Option>
          {CREDIT_ROLES.map((role) => (
            <Option key={role} value={role}>
              {ROLE_LABEL[role]}
            </Option>
          ))}
        </FilterSelect>

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

        {/* The same rule the library draws: everything left of it narrows the list,
            everything right of it only reorders what is left. */}
        <div className="col-span-2 flex items-end gap-3 sm:ml-auto sm:border-l sm:border-muted sm:pl-6">
          <FilterSelect
            label="Trier par"
            value={state.sort}
            onChange={(sort) => onSortChange(sort as PersonSortField)}
            className="w-full sm:w-44"
          >
            {PERSON_SORT_OPTIONS.map((option) => (
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
            <Button
              variant="ghost"
              size="icon"
              onClick={onReset}
              title="Réinitialiser"
              aria-label="Réinitialiser les filtres"
            >
              <X className="h-4 w-4" strokeWidth={2} />
            </Button>
          )}
        </div>
      </div>
    </div>
  )
}
