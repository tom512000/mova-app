import type { ReactNode } from 'react'
import { Moon, Sun } from 'lucide-react'
import { useTheme } from '@/hooks/useTheme'
import { MovaLogo } from '@/components/MovaLogo'

const EDITION_DATE = new Date().toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })

/**
 * Masthead shell for the signed-out pages. Deliberately not AppLayout: that one carries the
 * profile switcher, the share button and the main nav, none of which mean anything before
 * there is a session.
 */
export function AuthLayout({ subtitle, children }: { subtitle: string; children: ReactNode }) {
  const { theme, toggleTheme } = useTheme()

  return (
    <div className="flex min-h-screen flex-col bg-paper text-ink">
      <header className="border-b-4 border-ink">
        <div className="mx-auto flex max-w-screen-xl flex-wrap items-center justify-between gap-2 px-4 py-2 font-mono text-[10px] uppercase tracking-widest text-subtle">
          <span>{'dark' === theme ? 'Édition nuit' : 'Édition papier'}</span>
          <span>{EDITION_DATE}</span>
        </div>
      </header>

      <main className="mx-auto flex w-full max-w-md flex-1 flex-col justify-center px-4 py-12">
        <h1 className="flex justify-center">
          <MovaLogo mark="lockup" className="h-24 w-auto sm:h-28" />
        </h1>
        <p className="mt-2 text-center font-body text-sm italic text-subtle">{subtitle}</p>
        {children}
      </main>

      <footer className="border-t-4 border-ink">
        <div className="mx-auto flex max-w-screen-xl items-center justify-between px-4 py-6 font-mono text-[10px] uppercase tracking-widest text-subtle">
          <MovaLogo mark="monogram" className="h-6 w-auto" />
          <button
            onClick={toggleTheme}
            aria-label={theme === 'dark' ? 'Passer en édition papier (clair)' : 'Passer en édition nuit (sombre)'}
            className="flex h-11 w-11 items-center justify-center border border-ink text-ink transition-colors duration-200 hover:bg-ink hover:text-paper"
          >
            {theme === 'dark' ? <Sun className="h-4 w-4" strokeWidth={1.5} /> : <Moon className="h-4 w-4" strokeWidth={1.5} />}
          </button>
        </div>
      </footer>
    </div>
  )
}
