import { Route, Routes } from 'react-router-dom'
import { AppLayout } from '@/layouts/AppLayout'
import { RequireAuth } from '@/components/RequireAuth'
import { DashboardPage } from '@/pages/DashboardPage'
import { MoviesPage } from '@/pages/MoviesPage'
import { MovieDetailPage } from '@/pages/MovieDetailPage'
import { WatchlistPage } from '@/pages/WatchlistPage'
import { ImportPage } from '@/pages/ImportPage'
import { LoginPage } from '@/pages/LoginPage'
import { SharePage } from '@/pages/SharePage'

export function App() {
  return (
    <Routes>
      <Route path="/login" element={<LoginPage />} />

      <Route element={<RequireAuth />}>
        <Route element={<AppLayout />}>
          <Route index element={<DashboardPage />} />
          <Route path="movies" element={<MoviesPage />} />
          <Route path="movies/:id" element={<MovieDetailPage />} />
          <Route path="watchlist" element={<WatchlistPage />} />
          <Route path="import" element={<ImportPage />} />
          <Route path="share/:token" element={<SharePage />} />
        </Route>
      </Route>
    </Routes>
  )
}
