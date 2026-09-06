import { lazy } from 'react'
import { Route, Routes } from 'react-router-dom'
import { AppLayout } from '@/layouts/AppLayout'
import { RequireAuth } from '@/components/RequireAuth'
import { ScrollToTop } from '@/components/ScrollToTop'
import { MoviesPage } from '@/pages/MoviesPage'
import { MovieDetailPage } from '@/pages/MovieDetailPage'
import { WatchlistPage } from '@/pages/WatchlistPage'
import { LoginPage } from '@/pages/LoginPage'
import { RegisterPage } from '@/pages/RegisterPage'
import { NotFoundPage } from '@/pages/NotFoundPage'

/*
 * Split points, chosen by what a first-time visitor is made to pay for.
 *
 * Everything used to ship in one 857 kB bundle, so somebody arriving on the login page from
 * a search result downloaded Recharts and eight games before they could type an email. The
 * routes below are the heavy or rare ones; what stays imported above is the core somebody
 * actually touches on the way in.
 *
 * The dashboard is lazy despite being the index route, because Recharts is the single
 * largest dependency in the project and it is only ever needed *after* signing in. Its
 * chunk is fetched while the session check is in flight, and the page opens on a skeleton
 * of its own anyway while the eleven stats queries run — so the fallback below replaces a
 * loading state with a loading state rather than adding one.
 */
const DashboardPage = lazy(() => import('@/pages/DashboardPage').then((m) => ({ default: m.DashboardPage })))
const MuseumPage = lazy(() => import('@/pages/MuseumPage').then((m) => ({ default: m.MuseumPage })))
const ImportPage = lazy(() => import('@/pages/ImportPage').then((m) => ({ default: m.ImportPage })))
const AccountPage = lazy(() => import('@/pages/AccountPage').then((m) => ({ default: m.AccountPage })))
const SharePage = lazy(() => import('@/pages/SharePage').then((m) => ({ default: m.SharePage })))
const PersonPage = lazy(() => import('@/pages/PersonPage').then((m) => ({ default: m.PersonPage })))
const RetrospectivePage = lazy(() =>
  import('@/pages/RetrospectivePage').then((m) => ({ default: m.RetrospectivePage }))
)

const ClueGamePage = lazy(() => import('@/pages/ClueGamePage').then((m) => ({ default: m.ClueGamePage })))
const ComparisonGamePage = lazy(() =>
  import('@/pages/ComparisonGamePage').then((m) => ({ default: m.ComparisonGamePage }))
)
const PosterGamePage = lazy(() => import('@/pages/PosterGamePage').then((m) => ({ default: m.PosterGamePage })))
const HangmanGamePage = lazy(() => import('@/pages/HangmanGamePage').then((m) => ({ default: m.HangmanGamePage })))
const TaglineGamePage = lazy(() => import('@/pages/TaglineGamePage').then((m) => ({ default: m.TaglineGamePage })))
const BackdropGamePage = lazy(() => import('@/pages/BackdropGamePage').then((m) => ({ default: m.BackdropGamePage })))
const DuelGamePage = lazy(() => import('@/pages/DuelGamePage').then((m) => ({ default: m.DuelGamePage })))
const TimelineGamePage = lazy(() => import('@/pages/TimelineGamePage').then((m) => ({ default: m.TimelineGamePage })))

export function App() {
  return (
    <>
      <ScrollToTop />
      <Routes>
        <Route path="/login" element={<LoginPage />} />
        <Route path="/register" element={<RegisterPage />} />

        <Route element={<RequireAuth />}>
          <Route element={<AppLayout />}>
            <Route index element={<DashboardPage />} />
            <Route path="movies" element={<MoviesPage />} />
            <Route path="movies/:id" element={<MovieDetailPage />} />
            <Route path="people/:id" element={<PersonPage />} />
            <Route path="watchlist" element={<WatchlistPage />} />
            <Route path="museum" element={<MuseumPage />} />
            <Route path="retrospective" element={<RetrospectivePage />} />
            <Route path="games/clue/:mode" element={<ClueGamePage />} />
            <Route path="games/compare/:mode" element={<ComparisonGamePage />} />
            <Route path="games/poster/:mode" element={<PosterGamePage />} />
            <Route path="games/hangman/:mode" element={<HangmanGamePage />} />
            <Route path="games/tagline/:mode" element={<TaglineGamePage />} />
            <Route path="games/backdrop/:mode" element={<BackdropGamePage />} />
            <Route path="games/duel/:mode" element={<DuelGamePage />} />
            <Route path="games/timeline/:mode" element={<TimelineGamePage />} />
            <Route path="import" element={<ImportPage />} />
            <Route path="account" element={<AccountPage />} />
            <Route path="share/:token" element={<SharePage />} />

            {/* Inside the layout, so an unknown address still arrives on a page with a way
                out of it rather than on a bare screen. */}
            <Route path="*" element={<NotFoundPage />} />
          </Route>
        </Route>
      </Routes>
    </>
  )
}
