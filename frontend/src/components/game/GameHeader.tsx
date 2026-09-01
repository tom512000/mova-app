import { NavLink } from 'react-router-dom'
import type { ReactNode } from 'react'
import { formatDate } from '@/utils/format'
import { cn } from '@/utils/cn'
import type { GameKind, GameMode } from '@/types/api'
import { PageMeta } from '@/components/PageMeta'

const MODES: { value: GameMode; label: string; blurb: string }[] = [
  { value: 'daily', label: 'Quotidien', blurb: 'Un film par jour, une seule partie.' },
  { value: 'infinite', label: 'Infini', blurb: 'Autant de parties que tu veux.' },
]

/**
 * The masthead every game shares: the title, how it is played, and the switch between the
 * two modes. The modes are routes rather than local state so a mode can be bookmarked.
 */
export function GameHeader({
  game,
  mode,
  title,
  rules,
  puzzleDate,
}: {
  game: GameKind
  mode: GameMode
  title: string
  rules: ReactNode
  puzzleDate?: string | null
}) {
  return (
    <div className="border-b-4 border-ink pb-6">
      <PageMeta title={title} />
      <h1 className="font-serif text-5xl font-black tracking-tighter sm:text-6xl">{title}</h1>
      <p className="mt-2 max-w-xl font-body text-sm text-subtle">{rules}</p>

      <div className="mt-5 flex flex-wrap gap-1">
        {MODES.map((entry) => (
          <NavLink
            key={entry.value}
            to={`/games/${game}/${entry.value}`}
            className={({ isActive }) =>
              cn(
                'border-b-2 px-4 py-2 font-sans text-xs font-semibold uppercase tracking-widest transition-colors',
                isActive ? 'border-accent text-accent' : 'border-transparent text-subtle hover:text-ink'
              )
            }
          >
            {entry.label}
          </NavLink>
        ))}
        <p className="w-full pt-2 font-mono text-[10px] uppercase tracking-widest text-subtle">
          {MODES.find((entry) => entry.value === mode)?.blurb}
          {mode === 'daily' && puzzleDate && ` · ${formatDate(puzzleDate)}`}
        </p>
      </div>
    </div>
  )
}
