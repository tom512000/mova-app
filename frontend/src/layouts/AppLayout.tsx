import { Suspense, useEffect, useId, useState } from 'react'
import { Link, NavLink, Outlet } from 'react-router-dom'
import { LogOut, Menu, Moon, Share2, Sun, UserRound, X } from 'lucide-react'
import { useTheme } from '@/hooks/useTheme'
import { useSession } from '@/hooks/useSession'
import { ProfileSwitcher } from '@/components/ProfileSwitcher'
import { NavDropdown, type NavDropdownEntry } from '@/components/NavDropdown'
import { menuItemClass, navItemClass } from '@/layouts/navItemClass'
import { ShareProfileDialog } from '@/components/ShareProfileDialog'
import { MovaLogo } from '@/components/MovaLogo'
import { PageMeta } from '@/components/PageMeta'
import { SkeletonPage } from '@/components/Skeleton'
import { cn } from '@/utils/cn'

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
  { to: '/movies', label: 'Films et séries' },
  // Beside the films rather than under them: the two are the same library read down
  // two axes, and neither is a subsection of the other.
  { to: '/people', label: 'Personnes' },
  { to: '/watchlist', label: 'Watchlist' },
  // Read-only like the listings, so it stays available while viewing someone else's profile.
  { to: '/museum', label: 'Musée' },
  // Read-only too: it reports on a year of the viewed profile, so it has something to say
  // about somebody else's library just as much as about your own.
  { to: '/retrospective', label: 'Rétrospective' },
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
      { to: '/games/tagline/daily', label: "L'accroche", hint: 'La phrase qui a vendu le film' },
      { to: '/games/backdrop/daily', label: 'Le décor', hint: 'Un plan du film, sans le titre ni les visages' },
      // The two that are not about recognising a film: they come last, after the six that
      // are, so the list still reads as one idea before it changes register.
      { to: '/games/duel/daily', label: 'Le duel', hint: 'Lequel des deux as-tu noté le plus haut ?' },
      { to: '/games/timeline/daily', label: 'La chronologie', hint: 'Cinq films à remettre par date de sortie' },
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
  const [isMenuOpen, setIsMenuOpen] = useState(false)
  const menuId = useId()

  const navItems = NAV_ITEMS.filter((item) => !item.ownerOnly || !isViewingOtherProfile)
  const closeMenu = () => setIsMenuOpen(false)

  useEffect(() => {
    if (!isMenuOpen) return

    function onKeyDown(event: KeyboardEvent) {
      if (event.key === 'Escape') setIsMenuOpen(false)
    }

    document.addEventListener('keydown', onKeyDown)
    return () => document.removeEventListener('keydown', onKeyDown)
  }, [isMenuOpen])

  /**
   * The share button, the account and the logout, drawn twice: in the masthead's top rule
   * from lg up, and at the foot of the menu below that. On a phone the rule could not hold
   * them — it overflowed at 320 pixels, and each one was a fifteen-pixel-high tap target.
   */
  const sessionControls = (itemClassName: string) => (
    <>
      <ProfileSwitcher />
      {!isViewingOtherProfile && (
        <button
          type="button"
          onClick={() => {
            closeMenu()
            setIsSharing(true)
          }}
          className={itemClassName}
        >
          <Share2 className="h-3 w-3" strokeWidth={2} />
          Partager
        </button>
      )}
      <Link to="/account" onClick={closeMenu} className={itemClassName}>
        <UserRound className="h-3 w-3" strokeWidth={2} />
        <span className="max-w-32 truncate">{user?.displayName ?? 'Mon compte'}</span>
      </Link>
      <button type="button" onClick={() => void logout()} className={itemClassName}>
        <LogOut className="h-3 w-3" strokeWidth={2} />
        Déconnexion
      </button>
    </>
  )

  return (
    <div className="min-h-screen bg-paper text-ink">
      {/* Everything behind the login is off limits to search engines, and saying so once
          here covers every page the layout wraps — including any added later, which is the
          whole reason it lives at the layout rather than on each screen. */}
      <PageMeta noindex />

      {/* Sticky from lg only. Below it the full nav wrapped onto three rows and the sticky
          masthead kept 293 pixels of an 812-pixel phone for itself on every scroll — 57% of
          a 568-pixel one. The compact bar scrolls away with the page instead, which matters
          most in the games, where the on-screen keyboard takes the other half. */}
      <header className="z-40 border-b-4 border-ink bg-paper lg:sticky lg:top-0">
        <div className="mx-auto max-w-screen-xl px-4">
          <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 border-b border-ink/15 py-1.5 font-mono text-[10px] uppercase tracking-widest text-subtle">
            {/* A masthead names the edition you are holding, and this one is real: it
                follows the theme, so the strip says something true instead of carrying a
                volume number that was never going to reach II. */}
            <span>
              {'dark' === theme ? 'Édition nuit' : 'Édition papier'}
              {/* The date rides with the edition rather than sitting at the far end of
                  the strip: together they are one masthead line. The separator travels
                  inside the same span as the date it separates, so the narrow layout
                  that drops the date does not leave a dot dangling. */}
              <span className="hidden sm:inline"> &middot; {EDITION_DATE}</span>
            </span>

            <div className="hidden items-center gap-4 lg:flex">
              {sessionControls('inline-flex items-center gap-1.5 uppercase tracking-widest transition-colors hover:text-accent')}
            </div>
          </div>

          <div className="flex items-center justify-between gap-4 py-3 lg:py-3.5">
            <div className="flex min-w-0 flex-col items-start">
              <NavLink to="/" onClick={closeMenu} aria-label="Mova, retour au dashboard" className="block">
                <MovaLogo mark="wordmark" className="h-9 w-auto lg:h-11" />
              </NavLink>
              {isViewingOtherProfile && activeProfile && (
                <p className="mt-1 font-mono text-[10px] uppercase tracking-widest text-accent">
                  Profil de {activeProfile.displayName} &middot; lecture seule
                </p>
              )}
            </div>

            <div className="flex shrink-0 items-center gap-1">
              <nav className="hidden items-center gap-1 lg:flex" aria-label="Navigation principale">
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

              <button
                type="button"
                onClick={() => setIsMenuOpen((open) => !open)}
                aria-expanded={isMenuOpen}
                aria-controls={menuId}
                className={cn(
                  'flex h-11 items-center gap-2 border border-ink px-3 font-sans text-xs font-semibold uppercase tracking-widest transition-colors duration-200 lg:hidden',
                  isMenuOpen ? 'bg-ink text-paper' : 'text-ink hover:bg-ink hover:text-paper'
                )}
              >
                {isMenuOpen ? <X className="h-4 w-4" strokeWidth={1.5} /> : <Menu className="h-4 w-4" strokeWidth={1.5} />}
                Menu
              </button>
            </div>
          </div>

          {/* The same entries as the row above, laid out for a thumb: two columns of
              44-pixel rows, and the games spelled out as a group of their own rather than
              behind a dropdown, which has nowhere to open inside a panel this size. */}
          {isMenuOpen && (
            <nav id={menuId} aria-label="Navigation principale" className="border-t border-ink/15 pb-3 lg:hidden">
              <ul className="grid grid-cols-2 gap-x-4 pt-1">
                {navItems.map((item) =>
                  'items' in item ? null : (
                    <li key={item.to}>
                      <NavLink
                        to={item.to}
                        end={item.end}
                        onClick={closeMenu}
                        className={({ isActive }) => menuItemClass(isActive)}
                      >
                        {item.label}
                      </NavLink>
                    </li>
                  )
                )}
              </ul>

              {navItems.map((item) =>
                'items' in item ? (
                  <div key={item.label} className="mt-1 border-t border-ink/15 pt-3">
                    <p className="font-mono text-[10px] uppercase tracking-widest text-subtle">{item.label}</p>
                    <ul className="grid grid-cols-2 gap-x-4">
                      {item.items.map((entry) => (
                        <li key={entry.to}>
                          <NavLink to={entry.to} onClick={closeMenu} className={({ isActive }) => menuItemClass(isActive)}>
                            {entry.label}
                          </NavLink>
                        </li>
                      ))}
                    </ul>
                  </div>
                ) : null
              )}

              <div className="mt-1 flex flex-wrap items-center gap-x-5 border-t border-ink/15 pt-1 font-mono text-[10px] uppercase tracking-widest text-subtle">
                {sessionControls('inline-flex min-h-11 items-center gap-1.5 uppercase tracking-widest transition-colors hover:text-accent')}
              </div>
            </nav>
          )}
        </div>
      </header>

      <main className="mx-auto max-w-screen-xl px-4 py-10 sm:py-12">
        {/* One boundary, and it sits inside the chrome rather than around it: the masthead
            and the nav stay put while a route's chunk arrives. A boundary per route would
            flash this again on every hop between chunks already downloaded. */}
        <Suspense fallback={<SkeletonPage />}>
          <Outlet />
        </Suspense>
      </main>

      <footer className="mt-16 border-t-4 border-ink">
        <div className="mx-auto flex max-w-screen-xl flex-col items-center gap-1 px-4 py-4 font-mono text-[10px] uppercase tracking-widest text-subtle sm:flex-row sm:justify-between">
          <MovaLogo mark="monogram" className="h-6 w-auto" />
          {/* The colophon: where the pages come from. TMDB asks to be credited for the
              artwork and metadata this whole library is built out of, and nothing else
              on the site said so. */}
          <span>Données Letterboxd &amp; TMDB &middot; {new Date().getFullYear()}</span>
        </div>
      </footer>

      {isSharing && <ShareProfileDialog onClose={() => setIsSharing(false)} />}
    </div>
  )
}
