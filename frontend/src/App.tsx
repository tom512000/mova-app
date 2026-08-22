import { Route, Routes } from 'react-router-dom'
import { AppLayout } from '@/layouts/AppLayout'
import { DashboardPage } from '@/pages/DashboardPage'
import { MoviesPage } from '@/pages/MoviesPage'
import { MovieDetailPage } from '@/pages/MovieDetailPage'
import { WatchlistPage } from '@/pages/WatchlistPage'
import { ImportPage } from '@/pages/ImportPage'

export function App() {
  return (
    <Routes>
      <Route element={<AppLayout />}>
        <Route index element={<DashboardPage />} />
        <Route path="movies" element={<MoviesPage />} />
        <Route path="movies/:id" element={<MovieDetailPage />} />
        <Route path="watchlist" element={<WatchlistPage />} />
        <Route path="import" element={<ImportPage />} />
      </Route>
    </Routes>
  )
}
