import { useState } from 'react'
import { Link, NavLink, Outlet } from 'react-router-dom'
import { LogOut, Moon, Share2, Sun, UserRound } from 'lucide-react'
import { useTheme } from '@/hooks/useTheme'
import { useSession } from '@/hooks/useSession'
import { ProfileSwitcher } from '@/components/ProfileSwitcher'
import { NavDropdown, type NavDropdownEntry } from '@/components/NavDropdown'
import { navItemClass } from '@/layouts/navItemClass'
import { ShareProfileDialog } from '@/components/ShareProfileDialog'

interface NavLinkItem {
  to: string
  label: string
  end?: boolean
  ownerOnly?: boolean
}

interface NavMenuItem {
  label: string
  matchPrefix: string
  items: NavDropdownEntry[]
  ownerOnly?: boolean
}

type NavItem = NavLinkItem | NavMenuItem

const NAV_ITEMS: NavItem[] = [
  { to: '/', label: 'Dashboard', end: true },
  { to: '/movies', label: 'Films' },
  { to: '/watchlist', label: 'Watchlist' },
  // Games are played, not browsed: like Import they act on the logged-in account, so they
  // go away while another profile is being viewed rather than pretending to act on it.
  {
    label: 'Jeux',
    matchPrefix: '/games',
    ownerOnly: true,
    items: [
      { to: '/games/clue/daily', label: 'Le film mystère', hint: 'Un indice de plus à chaque erreur' },
      { to: '/games/compare/daily', label: 'Le film comparé', hint: 'Chaque proposition se compare à la cible' },
      { to: '/games/poster/daily', label: 'Le film pixelisé', hint: 'Une affiche qui se précise à chaque essai' },
      { to: '/games/hangman/daily', label: 'Le film pendu', hint: 'Un titre à deviner lettre par lettre' },
    ],
  },
  // Import writes, and writing only ever targets the logged-in account — so it disappears
  // while another profile is being viewed rather than pretending to act on it.
  { to: '/import', label: 'Import', ownerOnly: true },
]

const EDITION_DATE = new Date().toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })

export function AppLayout() {
  const { theme, toggleTheme } = useTheme()
  const { user, activeProfile, isViewingOtherProfile, logout } = useSession()
  const [isSharing, setIsSharing] = useState(false)

  const navItems = NAV_ITEMS.filter((item) => !item.ownerOnly || !isViewingOtherProfile)

  return (
    <div className="min-h-screen bg-paper text-ink">
      <header className="sticky top-0 z-40 border-b-4 border-ink bg-paper">
        <div className="mx-auto max-w-screen-xl px-4">
          <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 border-b border-ink/15 py-2 font-mono text-[10px] uppercase tracking-widest text-subtle">
            <span>Vol. I &middot; Édition numérique</span>

            <div className="flex items-center gap-4">
              <ProfileSwitcher />
              {!isViewingOtherProfile && (
                <button
                  onClick={() => setIsSharing(true)}
                  className="inline-flex items-center gap-1.5 uppercase tracking-widest transition-colors hover:text-accent"
                >
                  <Share2 className="h-3 w-3" strokeWidth={2} />
                  Partager
                </button>
              )}
              <Link
                to="/account"
                className="inline-flex items-center gap-1.5 uppercase tracking-widest transition-colors hover:text-accent"
              >
                <UserRound className="h-3 w-3" strokeWidth={2} />
                <span className="max-w-32 truncate">{user?.displayName ?? 'Mon compte'}</span>
              </Link>
              <button
                onClick={() => void logout()}
                className="inline-flex items-center gap-1.5 uppercase tracking-widest transition-colors hover:text-accent"
              >
                <LogOut className="h-3 w-3" strokeWidth={2} />
                Déconnexion
              </button>
              <span className="hidden sm:inline">{EDITION_DATE}</span>
            </div>
          </div>

          <div className="flex flex-col items-center gap-5 py-7 sm:flex-row sm:justify-between sm:gap-4">
            <div className="flex flex-col items-center sm:items-start">
              <NavLink to="/" className="font-serif text-4xl font-black tracking-tighter sm:text-5xl">
                Ciné-Stats
              </NavLink>
              {isViewingOtherProfile && activeProfile && (
                <p className="mt-1 font-mono text-[10px] uppercase tracking-widest text-accent">
                  Profil de {activeProfile.displayName} &middot; lecture seule
                </p>
              )}
            </div>

            <div className="flex flex-wrap items-center justify-center gap-1">
              <nav className="flex flex-wrap items-center justify-center gap-1" aria-label="Navigation principale">
                {navItems.map((item) =>
                  'items' in item ? (
                    <NavDropdown
                      key={item.label}
                      label={item.label}
                      items={item.items}
                      matchPrefix={item.matchPrefix}
                    />
                  ) : (
                    <NavLink
                      key={item.to}
                      to={item.to}
                      end={item.end}
                      className={({ isActive }) => navItemClass(isActive)}
                    >
                      {item.label}
                    </NavLink>
                  )
                )}
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

      {isSharing && <ShareProfileDialog onClose={() => setIsSharing(false)} />}
    </div>
  )
}
