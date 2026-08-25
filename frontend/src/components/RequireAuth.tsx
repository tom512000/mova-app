import { Navigate, Outlet, useLocation } from 'react-router-dom'
import { useSession } from '@/hooks/useSession'

/**
 * Gate in front of every authenticated route. Renders nothing while /auth/me is in flight —
 * showing the login form during that window would flash it at users who *are* logged in.
 */
export function RequireAuth() {
  const { user, isLoading } = useSession()
  const location = useLocation()

  if (isLoading) return null

  if (!user) {
    // Carries the intended destination so a share link opened while logged out still lands
    // on the right page after signing in.
    return <Navigate to="/login" replace state={{ from: location.pathname + location.search }} />
  }

  return <Outlet />
}
