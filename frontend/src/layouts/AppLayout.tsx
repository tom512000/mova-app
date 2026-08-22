import { NavLink, Outlet } from 'react-router-dom'
import clsx from 'clsx'
import { useTheme } from '@/hooks/useTheme'

const NAV_ITEMS = [
  { to: '/', label: 'Dashboard', end: true },
  { to: '/movies', label: 'Films' },
  { to: '/watchlist', label: 'Watchlist' },
  { to: '/import', label: 'Import' },
]

export function AppLayout() {
  const { theme, toggleTheme } = useTheme()

  return (
    <div className="flex min-h-screen">
      <aside className="flex w-56 shrink-0 flex-col border-r border-neutral-200 bg-white dark:border-neutral-800 dark:bg-neutral-900">
        <div className="flex h-16 items-center px-5">
          <span className="text-lg font-semibold tracking-tight">🎞️ Ciné-stats</span>
        </div>
        <nav className="flex flex-1 flex-col gap-1 px-3">
          {NAV_ITEMS.map((item) => (
            <NavLink
              key={item.to}
              to={item.to}
              end={item.end}
              className={({ isActive }) =>
                clsx(
                  'rounded-lg px-3 py-2 text-sm font-medium transition-colors',
                  isActive
                    ? 'bg-neutral-900 text-white dark:bg-neutral-100 dark:text-neutral-900'
                    : 'text-neutral-600 hover:bg-neutral-100 dark:text-neutral-400 dark:hover:bg-neutral-800'
                )
              }
            >
              {item.label}
            </NavLink>
          ))}
        </nav>
        <div className="p-3">
          <button
            onClick={toggleTheme}
            className="w-full rounded-lg px-3 py-2 text-left text-sm text-neutral-600 hover:bg-neutral-100 dark:text-neutral-400 dark:hover:bg-neutral-800"
          >
            {theme === 'dark' ? '☀️ Mode clair' : '🌙 Mode sombre'}
          </button>
        </div>
      </aside>
      <main className="flex-1 overflow-x-hidden p-6 lg:p-8">
        <Outlet />
      </main>
    </div>
  )
}
