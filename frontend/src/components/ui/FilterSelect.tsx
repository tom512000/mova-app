import { ChevronDown } from 'lucide-react'
import type { ReactNode } from 'react'

/**
 * A labelled dropdown that sits on a rule rather than in a box.
 *
 * Lives here rather than beside one page's filters because two pages now narrow a list this
 * way — the library and the watchlist — and a filter bar that looked slightly different on
 * each would read as two different applications.
 */
export function FilterSelect({
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
export function Option({ value, children }: { value: string; children: ReactNode }) {
  return (
    <option value={value} className="bg-paper text-ink">
      {children}
    </option>
  )
}
