import { createContext, use, useCallback, useEffect, useMemo, useState, type ReactNode } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import {
  fetchCurrentUser,
  login as loginRequest,
  logout as logoutRequest,
  register as registerRequest,
} from '@/services/authService'
import { fetchProfiles } from '@/services/profileService'
import { setActiveProfileId } from '@/services/apiClient'
import type { AuthUser, ProfileSummary } from '@/types/api'

interface SessionValue {
  user: AuthUser | null
  isLoading: boolean
  profiles: ProfileSummary[]
  /** The profile currently being read. Equals the logged-in account unless one was picked. */
  activeProfile: ProfileSummary | null
  isViewingOtherProfile: boolean
  login: (email: string, password: string) => Promise<void>
  register: (email: string, displayName: string, password: string) => Promise<void>
  logout: () => Promise<void>
  switchProfile: (profileId: number) => void
}

const SessionContext = createContext<SessionValue | null>(null)

export function SessionProvider({ children }: { children: ReactNode }) {
  const queryClient = useQueryClient()
  const [activeProfileId, setActiveProfileIdState] = useState<number | null>(null)

  // Typed nullable because logout writes null into it to sign out without waiting for the
  // API; fetchCurrentUser itself only ever resolves to a user.
  const session = useQuery<AuthUser | null>({
    queryKey: ['auth', 'me'],
    queryFn: fetchCurrentUser,
    // A 401 here is the normal "not logged in" answer, not a transient failure, so retrying
    // it just delays the redirect to the login page.
    retry: false,
    staleTime: Infinity,
  })

  const user = session.data ?? null

  const profiles = useQuery({
    queryKey: ['profiles'],
    queryFn: fetchProfiles,
    enabled: user !== null,
  })

  const profileList = useMemo(() => profiles.data ?? [], [profiles.data])

  // The interceptor reads a module variable, so it has to be written before any query using
  // it runs. Doing it in an effect (rather than during render) keeps render side-effect-free.
  useEffect(() => {
    setActiveProfileId(activeProfileId)
  }, [activeProfileId])

  const switchProfile = useCallback(
    (profileId: number) => {
      const isSelf = profileList.find((p) => p.id === profileId)?.isSelf ?? false
      setActiveProfileId(isSelf ? null : profileId)
      setActiveProfileIdState(isSelf ? null : profileId)
      // Every cached film, stat and watchlist page belongs to the profile that was active
      // when it was fetched, so none of it survives the switch.
      void queryClient.invalidateQueries()
    },
    [profileList, queryClient]
  )

  const login = useCallback(
    async (email: string, password: string) => {
      const loggedIn = await loginRequest(email, password)
      setActiveProfileId(null)
      setActiveProfileIdState(null)
      queryClient.setQueryData(['auth', 'me'], loggedIn)
      await queryClient.invalidateQueries()
    },
    [queryClient]
  )

  // Registering signs the account in server-side, so the only thing left to do here is
  // adopt the returned user — exactly the same tail as login.
  const register = useCallback(
    async (email: string, displayName: string, password: string) => {
      const created = await registerRequest(email, displayName, password)
      setActiveProfileId(null)
      setActiveProfileIdState(null)
      queryClient.setQueryData(['auth', 'me'], created)
      await queryClient.invalidateQueries()
    },
    [queryClient]
  )

  const logout = useCallback(async () => {
    // The request goes out first, then the local session is dropped without waiting for it.
    // Logging out is a decision the UI can honour immediately, and awaiting the round trip
    // left the button looking dead for as long as the API took to answer.
    const request = logoutRequest()

    setActiveProfileId(null)
    setActiveProfileIdState(null)
    // null (not a removal) so the query resolves to "no user" right away and RequireAuth
    // redirects; removing it would flip the query back to loading and render nothing.
    queryClient.setQueryData(['auth', 'me'], null)

    try {
      await request
    } finally {
      // clear(), not invalidate: the next account must not briefly see the previous one's
      // cached films while its own requests are still in flight.
      queryClient.clear()
    }
  }, [queryClient])

  const activeProfile = useMemo(() => {
    if (profileList.length === 0) return null
    return profileList.find((p) => p.id === activeProfileId) ?? profileList.find((p) => p.isSelf) ?? null
  }, [profileList, activeProfileId])

  const value = useMemo<SessionValue>(
    () => ({
      user,
      isLoading: session.isLoading,
      profiles: profileList,
      activeProfile,
      isViewingOtherProfile: activeProfileId !== null,
      login,
      register,
      logout,
      switchProfile,
    }),
    [user, session.isLoading, profileList, activeProfile, activeProfileId, login, register, logout, switchProfile]
  )

  return <SessionContext value={value}>{children}</SessionContext>
}

export function useSession(): SessionValue {
  const context = use(SessionContext)
  if (context === null) throw new Error('useSession doit être utilisé dans un <SessionProvider>.')
  return context
}
