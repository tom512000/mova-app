import { NavLink, Outlet } from 'react-router-dom'
import { Moon, Sun } from 'lucide-react'
import { useTheme } from '@/hooks/useTheme'
import { cn } from '@/utils/cn'

const NAV_ITEMS = [
  { to: '/', label: 'Dashboard', end: true },
  { to: '/movies', label: 'Films' },
  { to: '/watchlist', label: 'Watchlist' },
  { to: '/import', label: 'Import' },
]

const EDITION_DATE = new Date().toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })

export function AppLayout() {
  const { theme, toggleTheme } = useTheme()

  return (
    <div className="min-h-screen bg-paper text-ink">
      <header className="sticky top-0 z-40 border-b-4 border-ink bg-paper">
        <div className="mx-auto max-w-screen-xl px-4">
          <div className="flex flex-wrap items-center justify-between gap-2 border-b border-ink/15 py-2 font-mono text-[10px] uppercase tracking-widest text-subtle">
            <span>Vol. I &middot; Édition numérique</span>
            <span>{EDITION_DATE}</span>
          </div>

          <div className="flex flex-col items-center gap-5 py-7 sm:flex-row sm:justify-between sm:gap-4">
            <NavLink to="/" className="font-serif text-4xl font-black tracking-tighter sm:text-5xl">
              Ciné-Stats
            </NavLink>

            <div className="flex flex-wrap items-center justify-center gap-1">
              <nav className="flex flex-wrap items-center justify-center gap-1" aria-label="Navigation principale">
                {NAV_ITEMS.map((item) => (
                  <NavLink
                    key={item.to}
                    to={item.to}
                    end={item.end}
                    className={({ isActive }) =>
                      cn(
                        'border-b-2 px-4 py-3 font-sans text-xs font-semibold uppercase tracking-widest transition-colors duration-200',
                        isActive ? 'border-accent text-accent' : 'border-transparent text-ink hover:text-accent'
                      )
                    }
                  >
                    {item.label}
                  </NavLink>
                ))}
              </nav>

              <button
                onClick={toggleTheme}
                aria-label={theme === 'dark' ? 'Passer en édition papier (clair)' : 'Passer en édition nuit (sombre)'}
                className="flex h-11 w-11 items-center justify-center border border-ink text-ink transition-colors duration-200 hover:bg-ink hover:text-paper"
              >
                {theme === 'dark' ? <Sun className="h-4 w-4" strokeWidth={1.5} /> : <Moon className="h-4 w-4" strokeWidth={1.5} />}
              </button>
            </div>
          </div>
        </div>
      </header>

      <main className="mx-auto max-w-screen-xl px-4 py-10 sm:py-12">
        <Outlet />
      </main>

      <footer className="mt-16 border-t-4 border-ink">
        <div className="mx-auto flex max-w-screen-xl flex-col items-center gap-1 px-4 py-6 font-mono text-[10px] uppercase tracking-widest text-subtle sm:flex-row sm:justify-between">
          <span>Ciné-Stats</span>
          <span>Vol. I &middot; {new Date().getFullYear()}</span>
        </div>
      </footer>
    </div>
  )
}
